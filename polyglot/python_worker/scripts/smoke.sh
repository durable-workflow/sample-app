#!/usr/bin/env bash
set -euo pipefail

# Polyglot end-to-end smoke. Runs every polyglot scenario and asserts the
# expected results. A non-zero exit fails the polyglot story.

scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${DURABLE_WORKFLOW_SERVER_URL:?DURABLE_WORKFLOW_SERVER_URL must be set}"
: "${DURABLE_WORKFLOW_AUTH_TOKEN:=test-token}"
: "${DURABLE_WORKFLOW_NAMESPACE:=default}"
: "${DURABLE_SERVER_IMAGE:=durableworkflow/server:0.2.111}"
: "${DURABLE_WORKFLOW_PHP_SDK_PIN:=durable-workflow/workflow:2.0.0-alpha.145@cd3923a3078b489ac96f7b337b989e608e82dcf8}"
: "${DURABLE_WORKFLOW_WATERLINE_PIN:=durable-workflow/waterline:2.0.0-alpha.47@ed6f886866ceb8247d0845564c091604b28673e8}"
export DURABLE_WORKFLOW_SERVER_URL DURABLE_WORKFLOW_AUTH_TOKEN DURABLE_WORKFLOW_NAMESPACE
export DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_PIN DURABLE_WORKFLOW_WATERLINE_PIN

printf '\n==> polyglot smoke: server image %s\n' "$DURABLE_SERVER_IMAGE"
python "$scripts_dir/polyglot_smoke.py"
