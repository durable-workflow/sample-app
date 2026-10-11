import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { loadRecords, verifySelection } from '../../scripts/ci/cancellation-cascade-browser.mjs';

function record() {
    const root = { root_request_id: 'root', cleanup_deadline_at: '2026-10-11T00:00:30Z',
        requester: { id: 'legacy-token', label: 'Admin', type: 'auth:token' } };
    const runs = ['parent', 'child'].map((role) => ({ run_id: `${role}-run`,
        lifecycle: 'cancelled', same_root_budget: true, terminal_event_type: 'WorkflowCancelled', request: root }));
    return { parent: 'php', child: 'rust', parent_workflow_id: 'parent-workflow', parent_run_id: 'parent-run',
        child_workflow_instance_id: 'child-workflow', child_workflow_run_id: 'child-run',
        root_request: { request_id: 'root', cleanup_deadline_at: root.cleanup_deadline_at },
        cancellation_cascade: { schema: 'durable-workflow.cancellation-cascade/v1', root, runs,
            selected_run_id: 'parent-run', inspection_complete: true, truncated: false, findings: [],
            edges: [{ parent_run_id: 'parent-run', child_run_id: 'child-run' }] } };
}

function response(original, selection = 'parent') {
    return { engine_source: 'service', status: 'cancelled',
        instance_id: selection === 'parent' ? original.parent_workflow_id : original.child_workflow_instance_id,
        selected_run_id: selection === 'parent' ? original.parent_run_id : original.child_workflow_run_id,
        cancellation_cascade: { ...structuredClone(original.cancellation_cascade), selected_run_id: `${selection}-run` } };
}

test('real parent and child selections must retain the same original cascade', () => {
    const original = record();
    for (const selection of ['parent', 'child']) {
        assert.doesNotThrow(() => verifySelection(response(original, selection), original, selection));
    }
});

test('different workflow, run, backend and terminal outcome cannot qualify a view', () => {
    const original = record();
    for (const [field, value] of [['instance_id', 'other'], ['selected_run_id', 'other'],
        ['engine_source', 'v2'], ['status', 'failed']]) {
        const payload = response(original);
        payload[field] = value;
        assert.throws(() => verifySelection(payload, original, 'parent'));
    }
});

test('fresh deadlines, substituted requesters and incomplete graphs cannot qualify', () => {
    const original = record();
    for (const mutate of [
        (view) => { view.root.cleanup_deadline_at = '2026-10-11T00:01:00Z'; },
        (view) => { view.root.requester.id = 'duplicate-administrator'; },
        (view) => { view.runs.pop(); },
        (view) => { view.inspection_complete = false; },
        (view) => { view.selected_run_id = 'unrelated-run'; },
    ]) {
        const payload = response(original);
        mutate(payload.cancellation_cascade);
        assert.throws(() => verifySelection(payload, original, 'parent'));
    }
});

test('the retained input requires all five real directions and matching access mode', () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'cancellation-browser-input-'));
    try {
        for (const direction of ['php-rust', 'python-rust', 'rust-php', 'rust-python', 'rust-rust']) {
            const original = record();
            [original.parent, original.child] = direction.split('-');
            fs.writeFileSync(path.join(directory, `child-cancellation-worker-recovery-${direction}.json`), JSON.stringify(original));
        }
        assert.equal(loadRecords(directory, 'legacy').length, 5);
        assert.throws(() => loadRecords(directory, 'runtime'));
        assert.throws(() => loadRecords(directory, 'unknown'));
        fs.unlinkSync(path.join(directory, 'child-cancellation-worker-recovery-rust-rust.json'));
        assert.throws(() => loadRecords(directory, 'legacy'));
    } finally {
        fs.rmSync(directory, { recursive: true });
    }
});
