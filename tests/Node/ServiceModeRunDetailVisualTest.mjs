import assert from 'node:assert/strict';
import test from 'node:test';

import {
    STATES,
    VIEWPORTS,
    runDetailFixture,
} from '../../vendor/durable-workflow/waterline/scripts/ci/run-detail-visual.mjs';

test('installed Waterline qualifies every responsive run-detail state', () => {
    assert.deepEqual(VIEWPORTS, [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'intermediate', width: 900, height: 768 },
        { name: 'mobile', width: 390, height: 844 },
        { name: 'short-height', width: 1280, height: 480 },
    ]);
    assert.deepEqual(STATES, [
        { name: 'streams-expanded', expanded: true },
        { name: 'streams-collapsed', expanded: false },
    ]);
    assert.equal(VIEWPORTS.length * STATES.length, 8);
});

test('installed Waterline run-detail fixture exercises service Workflow Streams', () => {
    const fixture = runDetailFixture();

    assert.equal(fixture.workflow_streams_mode, 'service');
    assert.equal(fixture.workflow_streams_available, true);
    assert.ok(fixture.workflow_streams.length > 0);
    assert.ok(fixture.workflow_streams.some(({ status }) => status === 'errored'));
});
