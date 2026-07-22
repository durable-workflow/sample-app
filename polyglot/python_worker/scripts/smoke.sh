#!/usr/bin/env bash
set -euo pipefail

# Polyglot end-to-end smoke. Runs every required polyglot scenario,
# asserts runnable surfaces, and emits machine-readable conformance
# metadata. A non-zero exit means a required published-artifact surface
# failed.

scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

semantic_version_from_text() {
    local value="${1:-}"

    if [[ "$value" =~ ([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?) ]]; then
        printf '%s\n' "${BASH_REMATCH[1]}"
    fi
}

require_artifact_env() {
    local name="$1"

    if [ -z "${!name:-}" ]; then
        printf 'polyglot smoke: %s must be set; run scripts/resolve-current-artifacts.sh before starting the smoke service\n' "$name" >&2
        exit 1
    fi
}

: "${DURABLE_WORKFLOW_SERVER_URL:?DURABLE_WORKFLOW_SERVER_URL must be set}"
: "${DURABLE_WORKFLOW_AUTH_TOKEN:=test-token}"
: "${DURABLE_WORKFLOW_NAMESPACE:=default}"
: "${DURABLE_WORKFLOW_PHP_SDK_PIN:=}"
: "${DURABLE_WORKFLOW_WORKFLOW_PIN:=}"
: "${DURABLE_WORKFLOW_WATERLINE_PIN:=}"
if [ -z "${DURABLE_WORKFLOW_CLI_VERSION:-}" ] && [ -n "${DURABLE_WORKFLOW_CLI_PIN:-}" ]; then
    DURABLE_WORKFLOW_CLI_VERSION="$(semantic_version_from_text "$DURABLE_WORKFLOW_CLI_PIN")"
fi
if [ -n "${DURABLE_WORKFLOW_PHP_SDK_PIN:-}" ]; then
    DURABLE_WORKFLOW_PHP_SDK_VERSION="${DURABLE_WORKFLOW_PHP_SDK_PIN#durable-workflow/sdk:}"
    DURABLE_WORKFLOW_PHP_SDK_VERSION="${DURABLE_WORKFLOW_PHP_SDK_VERSION%@*}"
fi
if [ -n "${DURABLE_WORKFLOW_WORKFLOW_PIN:-}" ]; then
    DURABLE_WORKFLOW_WORKFLOW_VERSION="${DURABLE_WORKFLOW_WORKFLOW_PIN#durable-workflow/workflow:}"
    DURABLE_WORKFLOW_WORKFLOW_VERSION="${DURABLE_WORKFLOW_WORKFLOW_VERSION%@*}"
fi
if [ -n "${DURABLE_WORKFLOW_WATERLINE_PIN:-}" ]; then
    DURABLE_WORKFLOW_WATERLINE_VERSION="${DURABLE_WORKFLOW_WATERLINE_PIN#durable-workflow/waterline:}"
    DURABLE_WORKFLOW_WATERLINE_VERSION="${DURABLE_WORKFLOW_WATERLINE_VERSION%@*}"
fi
if [ -z "${DURABLE_WORKFLOW_PHP_SDK_PIN:-}" ] && [ -n "${DURABLE_WORKFLOW_PHP_SDK_VERSION:-}" ]; then
    DURABLE_WORKFLOW_PHP_SDK_PIN="durable-workflow/sdk:${DURABLE_WORKFLOW_PHP_SDK_VERSION}@beta"
fi
if [ -z "${DURABLE_WORKFLOW_WORKFLOW_PIN:-}" ] && [ -n "${DURABLE_WORKFLOW_WORKFLOW_VERSION:-}" ]; then
    DURABLE_WORKFLOW_WORKFLOW_PIN="durable-workflow/workflow:${DURABLE_WORKFLOW_WORKFLOW_VERSION}@beta"
fi
if [ -z "${DURABLE_WORKFLOW_WATERLINE_PIN:-}" ] && [ -n "${DURABLE_WORKFLOW_WATERLINE_VERSION:-}" ]; then
    DURABLE_WORKFLOW_WATERLINE_PIN="durable-workflow/waterline:${DURABLE_WORKFLOW_WATERLINE_VERSION}@beta"
fi
require_artifact_env DURABLE_SERVER_IMAGE
require_artifact_env DURABLE_WORKFLOW_CLI_VERSION
require_artifact_env DURABLE_WORKFLOW_PYTHON_SDK_VERSION
: "${DURABLE_WORKFLOW_PYTHON_AVRO_VERSION:=1.12.1}"
require_artifact_env DURABLE_WORKFLOW_PYTHON_AVRO_VERSION
require_artifact_env DURABLE_WORKFLOW_RUST_SDK_VERSION
: "${DURABLE_WORKFLOW_RUST_AVRO_VERSION:=0.21.0}"
require_artifact_env DURABLE_WORKFLOW_RUST_AVRO_VERSION
require_artifact_env DURABLE_WORKFLOW_PHP_SDK_VERSION
require_artifact_env DURABLE_WORKFLOW_PHP_SDK_PIN
require_artifact_env DURABLE_WORKFLOW_WORKFLOW_VERSION
require_artifact_env DURABLE_WORKFLOW_WORKFLOW_PIN
require_artifact_env DURABLE_WORKFLOW_WATERLINE_VERSION
require_artifact_env DURABLE_WORKFLOW_WATERLINE_PIN
if [ -z "${DURABLE_WORKFLOW_CLI_PIN:-}" ]; then
    DURABLE_WORKFLOW_CLI_PIN="dw==${DURABLE_WORKFLOW_CLI_VERSION}"
fi
: "${DURABLE_WORKFLOW_WATERLINE_URL:=http://waterline:8081/waterline}"
: "${DURABLE_WORKFLOW_ARTIFACT_PROBE_URL:=http://waterline:8081/polyglot/conformance/artifacts}"
export DURABLE_WORKFLOW_SERVER_URL DURABLE_WORKFLOW_AUTH_TOKEN DURABLE_WORKFLOW_NAMESPACE
export DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_CLI_PIN DURABLE_WORKFLOW_PYTHON_SDK_VERSION
export DURABLE_WORKFLOW_PYTHON_AVRO_VERSION
export DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_RUST_AVRO_VERSION
export DURABLE_WORKFLOW_PHP_SDK_PIN DURABLE_WORKFLOW_WORKFLOW_PIN DURABLE_WORKFLOW_WATERLINE_PIN
export DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION
export DURABLE_WORKFLOW_WATERLINE_URL DURABLE_WORKFLOW_ARTIFACT_PROBE_URL

printf '\n==> polyglot smoke: server image %s\n' "$DURABLE_SERVER_IMAGE"
printf '==> polyglot smoke: dw CLI %s\n' "$DURABLE_WORKFLOW_CLI_PIN"
printf '==> polyglot smoke: artifact probe %s\n' "$DURABLE_WORKFLOW_ARTIFACT_PROBE_URL"
python "$scripts_dir/polyglot_smoke.py"
