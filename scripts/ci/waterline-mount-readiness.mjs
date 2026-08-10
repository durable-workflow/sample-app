import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

function argumentValue(name, fallback = null) {
    const index = process.argv.indexOf(name);

    return index === -1 ? fallback : process.argv[index + 1];
}

function loadPlaywright() {
    const roots = [process.cwd()];

    try {
        roots.push(execFileSync('npm', ['root', '--global'], { encoding: 'utf8' }).trim());
    } catch {
        // The local resolution below reports the actionable module error.
    }

    let lastError = null;

    for (const root of roots.filter(Boolean)) {
        try {
            const require = createRequire(path.join(root, 'package.json'));

            return require('playwright');
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError;
}

const baseUrl = argumentValue('--base-url', process.env.APP_URL || 'http://127.0.0.1:8000');
const screenshotPath = path.resolve(argumentValue('--screenshot', 'waterline-mounted.png'));
const reportPath = path.resolve(argumentValue('--report', 'waterline-mount.json'));
const launchOptions = { args: ['--no-sandbox'] };

if (process.env.CHROMIUM_EXECUTABLE_PATH) {
    launchOptions.executablePath = process.env.CHROMIUM_EXECUTABLE_PATH;
}

fs.mkdirSync(path.dirname(screenshotPath), { recursive: true });
fs.mkdirSync(path.dirname(reportPath), { recursive: true });

const browser = await loadPlaywright().chromium.launch(launchOptions);
const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
});
await context.addInitScript(() => localStorage.setItem('waterline-theme', 'dark'));
const page = await context.newPage();
const consoleErrors = [];
const pageErrors = [];
const requestFailures = [];
const relevantResponses = [];
let failure = null;

page.on('console', (message) => {
    if (message.type() === 'error') {
        consoleErrors.push({ text: message.text(), location: message.location() });
    }
});
page.on('pageerror', (error) => {
    pageErrors.push({ name: error.name, message: error.message, stack: error.stack || null });
});
page.on('requestfailed', (request) => {
    requestFailures.push({
        method: request.method(),
        resource_type: request.resourceType(),
        url: request.url(),
        error: request.failure()?.errorText || 'unknown request failure',
    });
});
page.on('response', (response) => {
    const url = response.url();

    if (url.includes('/vendor/waterline/') || url.includes('/waterline/api/flows/completed')) {
        relevantResponses.push({
            status: response.status(),
            resource_type: response.request().resourceType(),
            url,
        });
    }
});

try {
    await page.goto(new URL('/waterline/completed', baseUrl).href, {
        waitUntil: 'domcontentloaded',
        timeout: 30_000,
    });
    await page.waitForFunction(() => (
        document.getElementById('waterline')?.getAttribute('data-waterline-mounted') === 'true'
    ), undefined, { timeout: 20_000 });
    await page.getByRole('button', { name: 'View Options', exact: true }).waitFor({
        state: 'visible',
        timeout: 20_000,
    });
    const listRequestCompleted = relevantResponses.some((response) => (
        response.url.includes('/waterline/api/flows/completed') && response.status === 200
    ));
    if (!listRequestCompleted) {
        await page.waitForResponse((response) => (
            response.url().includes('/waterline/api/flows/completed')
            && response.status() === 200
        ), { timeout: 20_000 }).catch(() => {
            throw new Error('The mounted page did not complete its workflow-list request.');
        });
    }
    await page.waitForTimeout(500);

    if (pageErrors.length || consoleErrors.length || requestFailures.length) {
        throw new Error('The mounted page emitted browser errors.');
    }
} catch (error) {
    failure = {
        name: error instanceof Error ? error.name : 'Error',
        message: error instanceof Error ? error.message : String(error),
        stack: error instanceof Error ? error.stack : null,
    };
}

const pageState = await page.evaluate(() => {
    const mount = document.getElementById('waterline');
    const bodyText = document.body?.innerText || '';

    return {
        title: document.title,
        url: window.location.href,
        mount_present: mount !== null,
        mounted: mount?.getAttribute('data-waterline-mounted') === 'true',
        mount_child_count: mount?.childElementCount || 0,
        body_text_length: bodyText.trim().length,
        body_text_excerpt: bodyText.trim().slice(0, 500),
    };
}).catch(() => ({
    title: null,
    url: page.url(),
    mount_present: false,
    mounted: false,
    mount_child_count: 0,
    body_text_length: 0,
    body_text_excerpt: '',
}));

if (!failure && (!pageState.mounted || pageState.mount_child_count === 0 || pageState.body_text_length < 100)) {
    failure = {
        name: 'WaterlineMountError',
        message: 'Waterline mounted without a nonblank operator surface.',
        stack: null,
    };
}

await page.screenshot({ path: screenshotPath, fullPage: false }).catch((error) => {
    failure ||= {
        name: 'ScreenshotError',
        message: error instanceof Error ? error.message : String(error),
        stack: error instanceof Error ? error.stack : null,
    };
});

const workflowListRequest = relevantResponses.findLast((response) => (
    response.url.includes('/waterline/api/flows/completed')
)) || null;
const report = {
    schema: 'durable-workflow.sample-app.waterline-mount.v1',
    status: failure ? 'failed' : 'passed',
    page: pageState,
    workflow_list_request: workflowListRequest,
    relevant_responses: relevantResponses,
    page_errors: pageErrors,
    console_errors: consoleErrors,
    request_failures: requestFailures,
    screenshot: path.basename(screenshotPath),
    failure,
};

fs.writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`);
await context.close();
await browser.close();

if (failure) {
    throw new Error(`Waterline mount readiness failed: ${JSON.stringify(report)}`);
}

console.log(`WATERLINE_MOUNT PASS ${pageState.title} (${pageState.body_text_length} visible characters)`);
