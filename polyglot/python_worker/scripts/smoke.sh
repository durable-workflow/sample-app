#!/usr/bin/env bash
set -euo pipefail

# Polyglot end-to-end smoke. Runs every required polyglot scenario,
# asserts runnable surfaces, and emits machine-readable conformance
# metadata. A non-zero exit means a required published-artifact surface
# failed.

scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${DURABLE_WORKFLOW_SERVER_URL:?DURABLE_WORKFLOW_SERVER_URL must be set}"
: "${DURABLE_WORKFLOW_AUTH_TOKEN:=test-token}"
: "${DURABLE_WORKFLOW_NAMESPACE:=default}"
: "${DURABLE_SERVER_IMAGE:=durableworkflow/server:0.2.113}"
: "${DURABLE_WORKFLOW_CLI_VERSION:=0.1.38}"
: "${DURABLE_WORKFLOW_CLI_PIN:=durable-workflow/cli:0.1.38}"
: "${DURABLE_WORKFLOW_PHP_SDK_PIN:=durable-workflow/workflow:2.0.0-alpha.145@cd3923a3078b489ac96f7b337b989e608e82dcf8}"
: "${DURABLE_WORKFLOW_WATERLINE_PIN:=durable-workflow/waterline:2.0.0-alpha.48@4a3ac279f7f8037cf2f422e7b732ec0cdfba17c5}"
: "${DURABLE_WORKFLOW_WATERLINE_URL:=http://waterline:8081/waterline}"
export DURABLE_WORKFLOW_SERVER_URL DURABLE_WORKFLOW_AUTH_TOKEN DURABLE_WORKFLOW_NAMESPACE
export DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_CLI_PIN
export DURABLE_WORKFLOW_PHP_SDK_PIN DURABLE_WORKFLOW_WATERLINE_PIN DURABLE_WORKFLOW_WATERLINE_URL

printf '\n==> polyglot smoke: server image %s\n' "$DURABLE_SERVER_IMAGE"
printf '==> polyglot smoke: dw CLI %s\n' "$DURABLE_WORKFLOW_CLI_PIN"
python "$scripts_dir/polyglot_smoke.py"
