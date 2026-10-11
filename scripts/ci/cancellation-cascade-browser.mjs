import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const DIRECTIONS = ['php-rust', 'python-rust', 'rust-php', 'rust-python', 'rust-rust'];
const ACTORS = {
    legacy: { id: 'legacy-token', label: 'Admin', type: 'auth:token' },
    runtime: { id: 'fixture-cancellation-operator', label: 'Operator', type: 'auth:runtime-token' },
    anonymous: { id: 'anonymous', label: 'Admin', type: 'server' },
};

export function loadRecords(proofDirectory, mode) {
    assert.ok(['legacy', 'runtime', 'anonymous'].includes(mode), 'Unknown cancellation mode');
    return DIRECTIONS.map((direction) => {
        const record = JSON.parse(fs.readFileSync(path.join(proofDirectory,
            `child-cancellation-worker-recovery-${direction}.json`), 'utf8'));
        assert.equal(`${record.parent}-${record.child}`, direction);
        assert.equal(record.cancellation_mode ?? 'legacy', mode);
        const cascade = record.cancellation_cascade;
        assert.equal(cascade.schema, 'durable-workflow.cancellation-cascade/v1');
        assert.equal(cascade.inspection_complete, true);
        assert.equal(cascade.truncated, false);
        assert.deepEqual(cascade.findings, []);
        assert.equal(cascade.selected_run_id, record.parent_run_id);
        assert.equal(cascade.runs.length, 2);
        assert.equal(cascade.edges.length, 1);
        assert.equal(cascade.root.root_request_id, record.root_request.request_id);
        assert.equal(cascade.root.cleanup_deadline_at, record.root_request.cleanup_deadline_at);
        assert.deepEqual(cascade.root.requester, ACTORS[mode]);
        if (mode !== 'legacy') assert.deepEqual(record.cancellation_actor, ACTORS[mode]);
        for (const run of cascade.runs) {
            assert.equal(run.lifecycle, 'cancelled');
            assert.equal(run.same_root_budget, true);
            assert.equal(run.terminal_event_type, 'WorkflowCancelled');
            assert.equal(run.request.cleanup_deadline_at, cascade.root.cleanup_deadline_at);
            assert.equal(run.request.root_request_id, cascade.root.root_request_id);
        }
        assert.deepEqual(new Set(cascade.runs.map((run) => run.run_id)),
            new Set([record.parent_run_id, record.child_workflow_run_id]));
        return record;
    });
}

export function verifySelection(payload, record, selection) {
    const workflowId = selection === 'parent' ? record.parent_workflow_id : record.child_workflow_instance_id;
    const runId = selection === 'parent' ? record.parent_run_id : record.child_workflow_run_id;
    assert.equal(payload.engine_source, 'service');
    assert.equal(payload.instance_id, workflowId);
    assert.equal(payload.selected_run_id, runId);
    assert.equal(payload.status, 'cancelled');
    const actual = payload.cancellation_cascade;
    assert.equal(actual?.selected_run_id, runId);
    assert.deepEqual({ ...actual, selected_run_id: null },
        { ...record.cancellation_cascade, selected_run_id: null },
        'Connected Waterline cascade differs from the original SDK/Server evidence');
    return { workflowId, runId };
}

function renderedValues(cascade) {
    const root = cascade.root;
    const values = [root.root_request_id, root.requested_at, root.cleanup_deadline_at,
        root.reason, root.requester.id, root.requester.label, root.source];
    for (const run of cascade.runs) {
        values.push(run.workflow_id, run.run_id, run.workflow_type, run.request.request_id,
            run.request.parent_request_id, run.cleanup.finished_at, run.terminal_event_type,
            String(run.terminal_history_event_id), String(run.delivery.history_event_id));
        for (const recovery of run.cleanup_recovery) {
            values.push(recovery.activity_execution_id, recovery.attempt.original_lease_owner,
                recovery.attempt.lease_owner, String(recovery.history_event_id));
        }
    }
    return values.filter((value) => typeof value === 'string' && value.length > 0);
}

async function inspectPage(browser, baseUrl, record, selection, viewport, outputDirectory) {
    const workflowId = selection === 'parent' ? record.parent_workflow_id : record.child_workflow_instance_id;
    const runId = selection === 'parent' ? record.parent_run_id : record.child_workflow_run_id;
    const suffix = `instances/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`;
    const expectedApiPath = new URL(`${baseUrl}/api/${suffix}`).pathname;
    const page = await browser.newPage({ viewport, locale: 'en-US' });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const stem = `${record.parent}-${record.child}-${selection}-${viewport.width}`;
    try {
        // This fragment asks the published UI for full diagnostics. No routes or responses are mocked.
        const responsePromise = page.waitForResponse((response) =>
            new URL(response.url()).pathname === expectedApiPath, { timeout: 30_000 });
        const url = `${baseUrl}/flows/${suffix}#cancellationCascadeTitle`;
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30_000 });
        const response = await responsePromise;
        assert.equal(response.status(), 200, 'Selected-run HTTP request failed');
        const payload = await response.json();
        verifySelection(payload, record, selection);
        fs.writeFileSync(path.join(outputDirectory, `${stem}-response.json`), JSON.stringify(payload, null, 2));
        await page.locator('#cancellationCascadeTitle').waitFor({ state: 'visible', timeout: 30_000 });
        const section = page.locator('section[aria-labelledby="cancellationCascadeTitle"]');
        const articles = section.locator('article');
        assert.equal(await articles.count(), 2);
        const text = await section.innerText();
        for (const value of renderedValues(payload.cancellation_cascade)) {
            assert.ok(text.includes(value), `Missing rendered cancellation evidence: ${value}`);
        }
        for (const run of payload.cancellation_cascade.runs) {
            const article = articles.filter({
                has: page.getByText(`${run.workflow_id} / ${run.run_id}`, { exact: true }),
            });
            assert.equal(await article.count(), 1);
            const runText = await article.innerText();
            for (const value of ['Cancelled', 'Original root budget', 'Completed', 'WorkflowCancelled']) {
                assert.ok(runText.includes(value), `Missing rendered ${value} for ${run.run_id}`);
            }
            const selected = await article.getByText('Selected run', { exact: true }).count();
            assert.equal(selected, run.run_id === runId ? 1 : 0);
        }
        const geometry = await section.evaluate((element) => ({
            left: element.getBoundingClientRect().left,
            right: element.getBoundingClientRect().right,
            width: window.innerWidth,
            scrollWidth: element.scrollWidth,
            clientWidth: element.clientWidth,
        }));
        assert.ok(geometry.left >= -1 && geometry.right <= geometry.width + 1,
            'Cancellation view leaves the viewport');
        assert.ok(geometry.scrollWidth <= geometry.clientWidth + 1,
            'Cancellation evidence overflows horizontally');
        assert.deepEqual(errors, [], 'Published Waterline has unhandled browser errors');
        await section.screenshot({ path: path.join(outputDirectory, `${stem}.png`) });
        fs.writeFileSync(path.join(outputDirectory, `${stem}-text.txt`), text);
        return { direction: `${record.parent}-${record.child}`, selection, workflow_id: workflowId,
            run_id: runId, url, api_url: response.url(), http_status: response.status(),
            root_request_id: payload.cancellation_cascade.root.root_request_id,
            cleanup_deadline_at: payload.cancellation_cascade.root.cleanup_deadline_at,
            requester: payload.cancellation_cascade.root.requester, geometry,
            viewport, browser_errors: errors, screenshot: `${stem}.png` };
    } catch (error) {
        await page.screenshot({ path: path.join(outputDirectory, `${stem}-failed.png`), fullPage: true }).catch(() => {});
        throw error;
    } finally {
        await page.close();
    }
}

async function main() {
    const proofDirectory = process.env.CHILD_PROOF_DIR;
    const baseUrl = process.env.DURABLE_WORKFLOW_WATERLINE_URL?.replace(/\/$/, '');
    const mode = process.env.DURABLE_WORKFLOW_CANCELLATION_MODE;
    assert.ok(proofDirectory && baseUrl, 'Set CHILD_PROOF_DIR and DURABLE_WORKFLOW_WATERLINE_URL');
    const records = loadRecords(proofDirectory, mode);
    const image = JSON.parse(fs.readFileSync(path.join(proofDirectory, 'waterline-image.json'), 'utf8'));
    assert.equal(image.Labels?.['dev.durable-workflow.release.tag'],
        process.env.DURABLE_WORKFLOW_WATERLINE_VERSION, 'Wrong published Waterline image');
    assert.match(image.Labels?.['org.opencontainers.image.revision'] ?? '', /^[a-f0-9]{40}$/,
        'Missing exact Waterline source identity');
    assert.ok(image.RepoDigests?.length > 0, 'Missing published image digest');
    const outputDirectory = path.join(proofDirectory, 'browser');
    fs.mkdirSync(outputDirectory, { recursive: true });
    const { chromium } = await import('playwright');
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    const report = { mode, waterline_version: process.env.DURABLE_WORKFLOW_WATERLINE_VERSION,
        browser_version: browser.version(), image, started_at: new Date().toISOString(), checks: [] };
    try {
        for (const record of records) {
            for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
                report.checks.push(await inspectPage(browser, baseUrl, record, 'parent', viewport, outputDirectory));
            }
            report.checks.push(await inspectPage(browser, baseUrl, record, 'child',
                { width: 1440, height: 900 }, outputDirectory));
        }
        report.completed_at = new Date().toISOString();
        report.outcome = 'pass';
        console.log(JSON.stringify({ scenario: 'connected-waterline-cancellation-browser', mode,
            views: report.checks.length, outcome: report.outcome }));
    } finally {
        fs.writeFileSync(path.join(outputDirectory, 'summary.json'), JSON.stringify(report, null, 2));
        await browser.close();
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    await main();
}
