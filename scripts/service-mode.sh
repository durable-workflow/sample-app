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
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-sample-app-service-mode}"
export SERVICE_MODE_PORT="${SERVICE_MODE_PORT:-18081}"

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

diagnostics() {
    local status=$?
    if (( status != 0 )); then
        echo >&2
        echo 'Service mode did not finish. Current container status:' >&2
        "${compose[@]}" ps >&2 || true
        "${compose[@]}" logs --no-color --tail=80 server php-worker python-worker waterline >&2 || true
    fi
    return "$status"
}
trap diagnostics EXIT

echo 'Starting the standalone Server, Laravel worker, Python worker, and Waterline...'
started_ms="$(date +%s%3N)"

# Refresh moving public image tags and reuse them directly. This path has no
# Docker build step and never compiles an SDK or language runtime locally.
"${compose[@]}" pull --quiet mysql redis worker-app-setup observer-app-setup python-setup bootstrap server php-worker python-worker waterline journey browser-smoke
"${compose[@]}" down --remove-orphans
"${compose[@]}" up --no-build --force-recreate worker-app-setup observer-app-setup python-setup
"${compose[@]}" up --detach --no-build --force-recreate --wait server php-worker python-worker waterline

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

SERVICE_MODE_JOURNEY_JSON="$journey_json" \
SERVICE_MODE_STARTUP_MS="$startup_ms" \
SERVICE_MODE_ELAPSED_MS="$journey_elapsed_ms" \
SERVICE_MODE_BROWSER_MS="$browser_elapsed_ms" \
SERVICE_MODE_BROWSER_SCREENSHOT="$browser_evidence_name" \
SERVICE_MODE_MOUNT_EVIDENCE="$mount_evidence_name" \
SERVICE_MODE_DIALOG_MS="$dialog_elapsed_ms" \
SERVICE_MODE_DIALOG_EVIDENCE="${dialog_evidence_name}/summary.json" \
SERVICE_MODE_EVIDENCE_OUTPUT="$evidence_path" \
node <<'NODE'
const fs = require('node:fs');

const lines = process.env.SERVICE_MODE_JOURNEY_JSON
  .split(/\r?\n/)
  .map(line => line.trim())
  .filter(Boolean);
const result = JSON.parse(lines.at(-1));
const evidence = {
  schema: 'durable-workflow.sample-app.service-mode-evidence.v1',
  captured_at: new Date().toISOString(),
  compose_project: process.env.COMPOSE_PROJECT_NAME,
  startup_ms: Number(process.env.SERVICE_MODE_STARTUP_MS),
  journey_ms: Number(process.env.SERVICE_MODE_ELAPSED_MS),
  browser_ms: Number(process.env.SERVICE_MODE_BROWSER_MS),
  browser_screenshot: process.env.SERVICE_MODE_BROWSER_SCREENSHOT,
  mount_evidence: process.env.SERVICE_MODE_MOUNT_EVIDENCE,
  dialog_ms: Number(process.env.SERVICE_MODE_DIALOG_MS),
  dialog_evidence: process.env.SERVICE_MODE_DIALOG_EVIDENCE,
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
