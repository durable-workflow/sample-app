import assert from 'node:assert/strict';
import test from 'node:test';

import {
    NAVIGATION_STATES,
    PRESENTATIONS,
    STATES,
    STREAM_RESULTS,
    VIEWPORTS,
    runDetailFixture,
} from '../../vendor/durable-workflow/waterline/scripts/ci/run-detail-visual.mjs';

test('installed Waterline qualifies every responsive run-detail state', () => {
    assert.deepEqual(VIEWPORTS, [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'intermediate', width: 768, height: 1024 },
        { name: 'mobile', width: 390, height: 844 },
        { name: 'short-height', width: 1280, height: 360 },
    ]);
    assert.deepEqual(NAVIGATION_STATES, [
        { name: 'initial', fragment: null },
        { name: 'deep-section', fragment: 'workflowStreams' },
    ]);
    assert.deepEqual(PRESENTATIONS, ['embedded', 'service']);
    assert.deepEqual(STREAM_RESULTS, [
        'populated',
        'supported-empty',
        'unavailable',
        'degraded',
    ]);
    assert.deepEqual(
        STATES.map(({ name, presentation, result, expanded }) => ({
            name,
            presentation,
            result,
            expanded,
        })),
        PRESENTATIONS.flatMap((presentation) => [
            ...STREAM_RESULTS.map((result) => ({
                name: `${presentation}-${result}-expanded`,
                presentation,
                result,
                expanded: true,
            })),
            {
                name: `${presentation}-populated-collapsed`,
                presentation,
                result: 'populated',
                expanded: false,
            },
        ]),
    );
    assert.equal(
        VIEWPORTS.length * NAVIGATION_STATES.length * STATES.length,
        80,
    );
});

test('installed Waterline run-detail fixture exercises service Workflow Streams', () => {
    const fixture = runDetailFixture('service-populated');

    assert.equal(fixture.workflow_streams_mode, 'service');
    assert.equal(fixture.workflow_streams_available, true);
    assert.ok(fixture.workflow_streams.length > 0);
    assert.ok(fixture.workflow_streams.some(({ status }) => status === 'errored'));
});
