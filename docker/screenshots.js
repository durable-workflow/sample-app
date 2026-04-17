/**
 * Waterline Screenshot Generator
 *
 * Usage:
 *   npx playwright install chromium
 *   node docker/screenshots.js [base_url] [output_dir]
 *
 * Or via Docker:
 *   docker compose run --rm screenshots
 */
import { chromium } from 'playwright';
import path from 'path';
import fs from 'fs';

const BASE = process.argv[2] || process.env.APP_URL || 'http://app:8000';
const OUTPUT = process.argv[3] || process.env.OUTPUT_DIR || './screenshots';

if (!fs.existsSync(OUTPUT)) {
    fs.mkdirSync(OUTPUT, { recursive: true });
}

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
    });
    const page = await context.newPage();

    // Log errors for debugging
    page.on('pageerror', err => console.log('  PAGE-ERROR:', err.message.substring(0, 200)));

    // Login
    console.log('Logging in...');
    try {
        await page.goto(BASE + '/login', { waitUntil: 'networkidle', timeout: 15000 });
        await page.fill('input[name=email]', 'test@example.com');
        await page.fill('input[name=password]', 'password');
        await page.click('button[type=submit]');
        await page.waitForTimeout(3000);
        console.log('  Logged in. URL:', page.url());
    } catch (e) {
        console.log('  Login skipped:', e.message.substring(0, 100));
    }

    // Waterline pages to screenshot
    const pages = [
        { path: '/waterline',            name: '01-dashboard' },
        { path: '/waterline/running',    name: '02-running' },
        { path: '/waterline/completed',  name: '03-completed' },
        { path: '/waterline/failed',     name: '04-failed' },
        { path: '/waterline/cancelled',  name: '05-cancelled' },
        { path: '/waterline/terminated', name: '06-terminated' },
        { path: '/waterline/workers',    name: '07-workers' },
        { path: '/waterline/schedules',  name: '08-schedules' },
    ];

    for (const s of pages) {
        console.log(`  ${s.name}...`);
        try {
            await page.goto(BASE + s.path, { waitUntil: 'networkidle', timeout: 15000 });
            await page.waitForTimeout(3000);
            await page.screenshot({
                path: path.join(OUTPUT, s.name + '.png'),
                fullPage: true,
            });
            console.log('    OK');
        } catch (e) {
            console.log('    FAIL:', e.message.substring(0, 100));
            try {
                await page.screenshot({
                    path: path.join(OUTPUT, s.name + '-error.png'),
                    fullPage: true,
                });
            } catch (_) {}
        }
    }

    // Try to navigate into a workflow detail view
    console.log('  workflow-detail...');
    try {
        await page.goto(BASE + '/waterline', { waitUntil: 'networkidle', timeout: 15000 });
        await page.waitForTimeout(3000);
        const link = page.locator('a[href*="instances"], a[href*="flows"], tr a').first();
        if (await link.isVisible({ timeout: 5000 })) {
            await link.click();
            await page.waitForTimeout(4000);
            await page.screenshot({
                path: path.join(OUTPUT, '09-workflow-detail.png'),
                fullPage: true,
            });
            console.log('    OK');
        } else {
            console.log('    No workflow links visible');
        }
    } catch (e) {
        console.log('    Detail:', e.message.substring(0, 100));
    }

    await browser.close();

    const files = fs.readdirSync(OUTPUT).filter(f => f.endsWith('.png'));
    console.log(`\nDone. ${files.length} screenshots saved to ${OUTPUT}`);
    process.exit(files.length > 0 ? 0 : 1);
})();
