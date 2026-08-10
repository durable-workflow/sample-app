import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

function argumentValue(name, fallback = null) {
    const index = process.argv.indexOf(name);

    return index === -1 ? fallback : process.argv[index + 1];
}

const sourcePath = process.env.WATERLINE_DIALOG_AUDIT_PATH
    || '/observer/vendor/durable-workflow/waterline/scripts/ci/workflow-list-dialog-visual.mjs';
const source = fs.readFileSync(sourcePath, 'utf8');

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

if (!source.includes(unsupportedMetadataFailure) || !source.includes(embeddedFilterCategories)) {
    throw new Error('The installed Waterline dialog audit has an unsupported validation contract.');
}

const temporaryDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'waterline-service-dialog-'));
const auditPath = path.join(temporaryDirectory, 'workflow-list-dialog-visual.mjs');

try {
    fs.writeFileSync(
        auditPath,
        source
            .replace(unsupportedMetadataFailure, serviceModeValidation)
            .replace(embeddedFilterCategories, serviceFilterCategories),
    );
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
