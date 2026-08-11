import assert from 'node:assert/strict';
import test from 'node:test';

import { jsonContainerEntryCount } from '../../scripts/ci/json-empty-state.mjs';

test('accepts either PHP JSON representation of an empty container', () => {
    assert.equal(jsonContainerEntryCount({}), 0);
    assert.equal(jsonContainerEntryCount([]), 0);
});

test('counts populated containers and rejects non-container values', () => {
    assert.equal(jsonContainerEntryCount({ density: 'compact' }), 1);
    assert.equal(jsonContainerEntryCount(['compact']), 1);

    for (const value of [null, undefined, false, 0, '']) {
        assert.equal(jsonContainerEntryCount(value), null);
    }
});
