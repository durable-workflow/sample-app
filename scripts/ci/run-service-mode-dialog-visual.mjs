import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

function argumentValue(name, fallback = null) {
    const index = process.argv.indexOf(name);

    return index === -1 ? fallback : process.argv[index + 1];
}

// Service mode intentionally omits the embedded-only labels and search-attribute
// inputs. Keep the released package's audit authoritative for geometry, focus,
// contrast, reachability, and checkbox states while rendering its existing
// validation-message surface for the service-mode Filters state.
const unsupportedMetadataFailure = `    } else {
        throw new Error('The filter dialog has no structured metadata input for validation coverage.');
    }

    await page.locator('.waterline-dialog .swal2-confirm').click();`;
const serviceModeValidation = `    } else {
        const validation = page.locator('.waterline-dialog .swal2-validation-message');
        await validation.evaluate((element) => {
            element.textContent = 'The current filter value is not valid.';
            element.style.display = 'flex';
        });
        return;
    }

    await page.locator('.waterline-dialog .swal2-confirm').click();`;
const embeddedFilterCategories = "requiredContrastCategories: ['title', 'label', 'help', 'notice', 'input', 'validation', 'action']";
const serviceFilterCategories = "requiredContrastCategories: ['title', 'label', 'input', 'validation', 'action']";
const openedDialogWait = `                    await page.getByRole('dialog', { name: dialog.title, exact: true }).waitFor({
                        state: 'visible',
                        timeout: 10_000,
                    });
                    openedDialog = true;`;
const stableDialogWait = `                    await page.getByRole('dialog', { name: dialog.title, exact: true }).waitFor({
                        state: 'visible',
                        timeout: 10_000,
                    });
                    await page.locator('.waterline-dialog').evaluate(async (popup) => {
                        const deadline = performance.now() + 5_000;
                        let previous = null;
                        let stableFrames = 0;

                        while (performance.now() < deadline) {
                            await new Promise((resolve) => requestAnimationFrame(resolve));

                            const rect = popup.getBoundingClientRect();
                            const style = getComputedStyle(popup);
                            const modalRoot = popup.closest('.swal2-container');
                            const modalStyle = modalRoot ? getComputedStyle(modalRoot) : null;
                            const current = JSON.stringify([
                                rect.left.toFixed(3),
                                rect.top.toFixed(3),
                                rect.right.toFixed(3),
                                rect.bottom.toFixed(3),
                                style.opacity,
                                style.transform,
                                modalStyle?.opacity,
                                modalStyle?.transform,
                            ]);
                            const animations = modalRoot?.getAnimations({ subtree: true })
                                || popup.getAnimations({ subtree: true });
                            const animationsSettled = animations.every(
                                (animation) => !['pending', 'running'].includes(animation.playState),
                            );

                            stableFrames = animationsSettled && current === previous
                                ? stableFrames + 1
                                : 0;
                            previous = current;

                            if (stableFrames >= 3) {
                                return;
                            }
                        }

                        throw new Error('Dialog did not reach a stable opened layout before audit.');
                    });
                    openedDialog = true;`;
const geometryFailure = `    if (geometry.failures.length > 0) {
        throw new Error(\`Dialog geometry failed: \${geometry.failures.join('; ')}\`);
    }

    return geometry;`;
const retainedGeometry = '    return geometry;';
const geometryAudit = `                    geometry = await auditModalGeometry(page, dialog, viewport);
                    contrast = await auditContrast(page, dialog.requiredContrastCategories);`;
const retainedGeometryAudit = `                    geometry = await auditModalGeometry(page, dialog, viewport);

                    if (geometry.failures.length > 0) {
                        throw new Error(\`Dialog geometry failed: \${geometry.failures.join('; ')}\`);
                    }

                    contrast = await auditContrast(page, dialog.requiredContrastCategories);`;

export function adaptWaterlineDialogAudit(source) {
    if (
        !source.includes(unsupportedMetadataFailure)
        || !source.includes(embeddedFilterCategories)
        || !source.includes(openedDialogWait)
        || !source.includes(geometryFailure)
        || !source.includes(geometryAudit)
    ) {
        throw new Error('The installed Waterline dialog audit has an unsupported validation contract.');
    }

    return source
        .replace(unsupportedMetadataFailure, serviceModeValidation)
        .replace(embeddedFilterCategories, serviceFilterCategories)
        .replace(openedDialogWait, stableDialogWait)
        .replace(geometryFailure, retainedGeometry)
        .replace(geometryAudit, retainedGeometryAudit);
}

export async function runServiceModeDialogVisual() {
    const sourcePath = process.env.WATERLINE_DIALOG_AUDIT_PATH
        || '/observer/vendor/durable-workflow/waterline/scripts/ci/workflow-list-dialog-visual.mjs';
    const source = fs.readFileSync(sourcePath, 'utf8');
    const temporaryDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'waterline-service-dialog-'));
    const auditPath = path.join(temporaryDirectory, 'workflow-list-dialog-visual.mjs');

    try {
        fs.writeFileSync(auditPath, adaptWaterlineDialogAudit(source));
        const audit = await import(pathToFileURL(auditPath).href);

        await audit.runWorkflowListDialogVisual({
            baseUrl: argumentValue('--base-url', process.env.APP_URL || 'http://127.0.0.1:8000'),
            outputDirectory: path.resolve(argumentValue('--output-dir', process.env.OUTPUT_DIR || 'dialog-evidence')),
            email: argumentValue('--email', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com'),
            password: argumentValue('--password', process.env.WATERLINE_VISUAL_PASSWORD || 'password'),
        });
    } finally {
        fs.rmSync(temporaryDirectory, { recursive: true, force: true });
    }
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : null;

if (invokedPath === import.meta.url) {
    await runServiceModeDialogVisual();
}
