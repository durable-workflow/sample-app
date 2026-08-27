#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${repo_root}/polyglot/service-mode.yml"
evidence_path="${SERVICE_MODE_EVIDENCE_PATH:-${repo_root}/storage/app/service-mode-evidence.json}"
mkdir -p "$(dirname "$evidence_path")"
export SERVICE_MODE_EVIDENCE_DIR="$(cd "$(dirname "$evidence_path")" && pwd)"
evidence_name="$(basename "$evidence_path")"
browser_evidence_name="${evidence_name%.json}-waterline.png"
mount_evidence_name="${evidence_name%.json}-waterline-mount.json"
dialog_evidence_name="${evidence_name%.json}-waterline-dialogs"
run_detail_evidence_name="${evidence_name%.json}-waterline-run-detail"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-sample-app-service-mode}"
export SERVICE_MODE_PORT="${SERVICE_MODE_PORT:-18081}"

sample_app_revision="${SERVICE_MODE_SAMPLE_APP_REVISION:-${GITHUB_SHA:-}}"
if [[ -z "$sample_app_revision" ]]; then
    sample_app_revision="$(git -C "$repo_root" rev-parse HEAD)"
fi
if [[ ! "$sample_app_revision" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Service mode needs an exact 40-character Sample App revision; got ${sample_app_revision}." >&2
    exit 1
fi

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    echo 'Service mode needs Docker Engine with the Compose v2 plugin.' >&2
    exit 1
fi

artifact_source="${DURABLE_WORKFLOW_ARTIFACT_SOURCE:-pinned}"
while IFS= read -r assignment; do
    export "$assignment"
done < <(
    DURABLE_WORKFLOW_ARTIFACT_SOURCE="$artifact_source" \
        "${repo_root}/scripts/resolve-current-artifacts.sh"
)

if [[ -z "${SERVICE_MODE_WATERLINE_URL:-}" ]]; then
    if [[ -n "${CODESPACE_NAME:-}" ]]; then
        codespaces_domain="${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
        export SERVICE_MODE_WATERLINE_URL="https://${CODESPACE_NAME}-${SERVICE_MODE_PORT}.${codespaces_domain}/waterline"
    else
        export SERVICE_MODE_WATERLINE_URL="http://localhost:${SERVICE_MODE_PORT}/waterline"
    fi
fi

compose=(docker compose --project-name "$COMPOSE_PROJECT_NAME" --file "$compose_file")

run_phase() {
    local phase="$1"
    shift

    echo "==> ${phase}"
    if "$@"; then
        return 0
    else
        local status=$?
        echo "Service mode failed during phase: ${phase}." >&2
        return "$status"
    fi
}

diagnostics() {
    local status=$?
    if (( status != 0 )); then
        echo >&2
        echo 'Service mode did not finish. Current container status:' >&2
        "${compose[@]}" ps >&2 || true
        "${compose[@]}" logs --no-color --tail=80 \
            mysql observer-app-setup waterline-migrate server php-worker python-worker \
            waterline waterline-embedded >&2 || true
    fi
    return "$status"
}
trap diagnostics EXIT

echo 'Starting the standalone Server, Laravel worker, Python worker, and Waterline...'
started_ms="$(date +%s%3N)"

# Refresh moving public image tags and reuse them directly. This path has no
# Docker build step and never compiles an SDK or language runtime locally.
run_phase "published artifact pull" \
    "${compose[@]}" pull --quiet \
        mysql redis worker-app-setup observer-app-setup python-setup waterline-migrate \
        bootstrap server php-worker python-worker waterline waterline-embedded \
        journey browser-smoke
run_phase "previous service shutdown" "${compose[@]}" down --remove-orphans
run_phase "application and language setup" \
    "${compose[@]}" up --no-build --force-recreate \
        worker-app-setup observer-app-setup python-setup
run_phase "database readiness" \
    "${compose[@]}" up --detach --no-build --wait mysql redis
run_phase "Waterline database migrations" \
    "${compose[@]}" up --no-build --force-recreate --no-deps \
        --exit-code-from waterline-migrate waterline-migrate
run_phase "service startup and readiness" \
    "${compose[@]}" up --detach --no-build --wait \
        server php-worker python-worker waterline waterline-embedded

installed_waterline_json="$(
    "${compose[@]}" exec -T waterline php -r '
require "vendor/autoload.php";
echo json_encode([
    "package" => "durable-workflow/waterline",
    "version" => \Composer\InstalledVersions::getPrettyVersion("durable-workflow/waterline"),
    "reference" => \Composer\InstalledVersions::getReference("durable-workflow/waterline"),
], JSON_THROW_ON_ERROR);
'
)"
SERVICE_MODE_INSTALLED_WATERLINE_JSON="$installed_waterline_json" \
node <<'NODE'
const installed = JSON.parse(process.env.SERVICE_MODE_INSTALLED_WATERLINE_JSON);
const expected = process.env.DURABLE_WORKFLOW_WATERLINE_VERSION;

if (
  installed.package !== 'durable-workflow/waterline'
  || installed.version !== expected
  || !/^[0-9a-f]{40}$/.test(installed.reference || '')
) {
  throw new Error(
    `Installed Waterline identity ${JSON.stringify(installed)} does not match ${expected}.`,
  );
}
NODE

startup_ms="$(( $(date +%s%3N) - started_ms ))"
echo "Ready in ${startup_ms} ms. Starting a unique Laravel workflow..."

result_started_ms="$(date +%s%3N)"
journey_json="$("${compose[@]}" run --no-deps --rm -T journey)"
journey_elapsed_ms="$(( $(date +%s%3N) - result_started_ms ))"

readarray -t waterline_paths < <(SERVICE_MODE_JOURNEY_JSON="$journey_json" node -e '
const lines = process.env.SERVICE_MODE_JOURNEY_JSON.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
const pagePath = new URL(JSON.parse(lines.at(-1)).waterline_url).pathname;
const apiPath = pagePath.replace("/flows/instances/", "/api/instances/");
if (apiPath === pagePath) {
  throw new Error(`Waterline run URL does not use the selected-run route: ${pagePath}`);
}
console.log(pagePath);
console.log(apiPath);
')
if (( ${#waterline_paths[@]} != 2 )); then
    echo 'Could not derive the Waterline page and selected-run API paths.' >&2
    exit 1
fi
waterline_page_path="${waterline_paths[0]}"
waterline_api_path="${waterline_paths[1]}"

# Prove both the browser shell and its exact selected-run data are reachable
# before retaining a screenshot or reporting success to the user.
"${compose[@]}" exec -T waterline curl --fail --silent --show-error \
    "http://localhost:8081${waterline_page_path}" >/dev/null
waterline_selection_json="$(
    "${compose[@]}" exec -T waterline curl --fail --silent --show-error \
        "http://localhost:8081${waterline_api_path}"
)"
SERVICE_MODE_JOURNEY_JSON="$journey_json" \
SERVICE_MODE_WATERLINE_SELECTION_JSON="$waterline_selection_json" \
node <<'NODE'
const journeyLines = process.env.SERVICE_MODE_JOURNEY_JSON
  .split(/\r?\n/)
  .map(line => line.trim())
  .filter(Boolean);
const journey = JSON.parse(journeyLines.at(-1));
const selection = JSON.parse(process.env.SERVICE_MODE_WATERLINE_SELECTION_JSON);

if (
  selection.instance_id !== journey.workflow_id
  || selection.selected_run_id !== journey.run_id
) {
  throw new Error(
    `Waterline selected ${selection.instance_id ?? 'unknown'}/${selection.selected_run_id ?? 'unknown'}; `
    + `expected ${journey.workflow_id}/${journey.run_id}.`,
  );
}
NODE

browser_started_ms="$(date +%s%3N)"
"${compose[@]}" run --no-deps --rm -T --entrypoint node browser-smoke \
    /observer/scripts/ci/waterline-mount-readiness.mjs \
    --base-url http://waterline:8081 \
    --screenshot "/evidence/${browser_evidence_name}" \
    --report "/evidence/${mount_evidence_name}"
browser_elapsed_ms="$(( $(date +%s%3N) - browser_started_ms ))"

dialog_started_ms="$(date +%s%3N)"
"${compose[@]}" run --no-deps --rm -T --entrypoint node browser-smoke \
    /observer/scripts/ci/run-service-mode-dialog-visual.mjs \
    --base-url http://waterline:8081 \
    --output-dir "/evidence/${dialog_evidence_name}"
dialog_elapsed_ms="$(( $(date +%s%3N) - dialog_started_ms ))"

run_detail_started_ms="$(date +%s%3N)"
"${compose[@]}" run --no-deps --rm -T --entrypoint node browser-smoke \
    /observer/vendor/durable-workflow/waterline/scripts/ci/run-detail-visual.mjs \
    --base-url http://waterline-embedded:8082 \
    --service-base-url http://waterline:8081 \
    --output-dir "/evidence/${run_detail_evidence_name}"
run_detail_elapsed_ms="$(( $(date +%s%3N) - run_detail_started_ms ))"

SERVICE_MODE_JOURNEY_JSON="$journey_json" \
SERVICE_MODE_SAMPLE_APP_REVISION="$sample_app_revision" \
SERVICE_MODE_INSTALLED_WATERLINE_JSON="$installed_waterline_json" \
SERVICE_MODE_STARTUP_MS="$startup_ms" \
SERVICE_MODE_ELAPSED_MS="$journey_elapsed_ms" \
SERVICE_MODE_BROWSER_MS="$browser_elapsed_ms" \
SERVICE_MODE_BROWSER_SCREENSHOT="$browser_evidence_name" \
SERVICE_MODE_MOUNT_EVIDENCE="$mount_evidence_name" \
SERVICE_MODE_DIALOG_MS="$dialog_elapsed_ms" \
SERVICE_MODE_DIALOG_EVIDENCE="${dialog_evidence_name}/summary.json" \
SERVICE_MODE_RUN_DETAIL_MS="$run_detail_elapsed_ms" \
SERVICE_MODE_RUN_DETAIL_EVIDENCE="${run_detail_evidence_name}/summary.json" \
SERVICE_MODE_EVIDENCE_OUTPUT="$evidence_path" \
node <<'NODE'
const fs = require('node:fs');

const lines = process.env.SERVICE_MODE_JOURNEY_JSON
  .split(/\r?\n/)
  .map(line => line.trim())
  .filter(Boolean);
const result = JSON.parse(lines.at(-1));
const evidence = {
  schema: 'durable-workflow.sample-app.service-mode-evidence.v2',
  captured_at: new Date().toISOString(),
  compose_project: process.env.COMPOSE_PROJECT_NAME,
  consumer: {
    repository: 'durable-workflow/sample-app',
    revision: process.env.SERVICE_MODE_SAMPLE_APP_REVISION,
  },
  installed: {
    waterline: JSON.parse(process.env.SERVICE_MODE_INSTALLED_WATERLINE_JSON),
  },
  ci: {
    event_name: process.env.GITHUB_EVENT_NAME || null,
    ref: process.env.GITHUB_REF || null,
    run_id: process.env.GITHUB_RUN_ID || null,
    run_attempt: process.env.GITHUB_RUN_ATTEMPT || null,
  },
  public_completion_gate: 'https://github.com/durable-workflow/waterline/issues/79',
  startup_ms: Number(process.env.SERVICE_MODE_STARTUP_MS),
  journey_ms: Number(process.env.SERVICE_MODE_ELAPSED_MS),
  browser_ms: Number(process.env.SERVICE_MODE_BROWSER_MS),
  browser_screenshot: process.env.SERVICE_MODE_BROWSER_SCREENSHOT,
  mount_evidence: process.env.SERVICE_MODE_MOUNT_EVIDENCE,
  dialog_ms: Number(process.env.SERVICE_MODE_DIALOG_MS),
  dialog_evidence: process.env.SERVICE_MODE_DIALOG_EVIDENCE,
  run_detail_ms: Number(process.env.SERVICE_MODE_RUN_DETAIL_MS),
  run_detail_evidence: process.env.SERVICE_MODE_RUN_DETAIL_EVIDENCE,
  workflow: result,
  artifacts: {
    server: process.env.DURABLE_SERVER_IMAGE,
    sdk_php: process.env.DURABLE_WORKFLOW_PHP_SDK_VERSION,
    sdk_python: process.env.DURABLE_WORKFLOW_PYTHON_SDK_VERSION,
    workflow: process.env.DURABLE_WORKFLOW_WORKFLOW_VERSION,
    waterline: process.env.DURABLE_WORKFLOW_WATERLINE_VERSION,
  },
};

fs.writeFileSync(
  process.env.SERVICE_MODE_EVIDENCE_OUTPUT,
  `${JSON.stringify(evidence, null, 2)}\n`,
);

console.log(`Completed workflow ${result.workflow_id} in ${result.result_ms} ms.`);
console.log(`PHP activity: ${result.result.php_activity.greeting}`);
console.log(`Python activity: ${result.result.python_activity.message}`);
console.log(`Inspect this exact run in Waterline: ${result.waterline_url}`);
console.log(`Browser proof: ${process.env.SERVICE_MODE_EVIDENCE_DIR}/${process.env.SERVICE_MODE_BROWSER_SCREENSHOT}`);
console.log(`Startup and result timings: ${process.env.SERVICE_MODE_EVIDENCE_OUTPUT}`);
NODE

trap - EXIT
