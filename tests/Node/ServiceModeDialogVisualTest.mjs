import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import { adaptWaterlineDialogAudit } from '../../scripts/ci/run-service-mode-dialog-visual.mjs';

const installedAuditPath = new URL(
    '../../vendor/durable-workflow/waterline/scripts/ci/workflow-list-dialog-visual.mjs',
    import.meta.url,
);

test('audits only stable dialogs and retains rejected geometry', () => {
    const source = fs.readFileSync(installedAuditPath, 'utf8');
    const adapted = adaptWaterlineDialogAudit(source);
    const geometryAssignment = adapted.indexOf(
        'geometry = await auditModalGeometry(page, dialog, viewport);',
    );
    const geometryRejection = adapted.indexOf(
        "throw new Error(`Dialog geometry failed: ${geometry.failures.join('; ')}`);",
        geometryAssignment,
    );

    assert.match(adapted, /getAnimations\(\{ subtree: true \}\)/);
    assert.match(adapted, /stableFrames >= 3/);
    assert.ok(geometryAssignment >= 0);
    assert.ok(geometryRejection > geometryAssignment);
    assert.equal(
        adapted.indexOf(
            "throw new Error(`Dialog geometry failed: ${geometry.failures.join('; ')}`);",
        ),
        geometryRejection,
    );
});

test('rejects an unsupported installed dialog audit', () => {
    assert.throws(
        () => adaptWaterlineDialogAudit('export const unrelatedAudit = true;'),
        /unsupported validation contract/,
    );
});
