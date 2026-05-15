#!/usr/bin/env bash
set -euo pipefail

# Polyglot end-to-end smoke. Runs both polyglot scenarios and asserts the
# expected results. A non-zero exit fails the polyglot story.

scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${DURABLE_WORKFLOW_SERVER_URL:?DURABLE_WORKFLOW_SERVER_URL must be set}"
: "${DURABLE_WORKFLOW_AUTH_TOKEN:=test-token}"
: "${DURABLE_WORKFLOW_NAMESPACE:=default}"
: "${DURABLE_SERVER_IMAGE:=durableworkflow/server:0.2.110}"
export DURABLE_WORKFLOW_SERVER_URL DURABLE_WORKFLOW_AUTH_TOKEN DURABLE_WORKFLOW_NAMESPACE

printf '\n==> polyglot smoke: server image %s\n' "$DURABLE_SERVER_IMAGE"

printf '\n==> polyglot smoke: python-authored workflow on a python worker\n'
python "$scripts_dir/python_workflow_smoke.py"

printf '\n==> polyglot smoke: php-authored workflow scheduling a python activity\n'
python "$scripts_dir/php_to_python_smoke.py"

printf '\npolyglot smoke: both scenarios passed\n'
