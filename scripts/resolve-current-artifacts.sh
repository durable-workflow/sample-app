#!/usr/bin/env bash
set -euo pipefail

default_server_image="durableworkflow/server:0.2.153"
default_cli_version="0.1.53"
default_python_sdk_version="0.4.64"
default_workflow_version="2.0.0-alpha.166"
default_waterline_version="2.0.0-alpha.57"

semantic_version_from_text() {
  local value="${1:-}"

  if [[ "$value" =~ ([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?) ]]; then
    printf '%s\n' "${BASH_REMATCH[1]}"
  fi
}

emit_assignment() {
  local name="$1"
  local value="$2"

  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    printf 'resolve-current-artifacts: %s contains a newline\n' "$name" >&2
    exit 1
  fi

  printf '%s=%s\n' "$name" "$value"
}

server_image="${DURABLE_SERVER_IMAGE:-$default_server_image}"
server_version="$(semantic_version_from_text "$server_image")"
server_version="${server_version:-$(semantic_version_from_text "$default_server_image")}"

cli_pin="${DURABLE_WORKFLOW_CLI_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_CLI_VERSION:-}" ]]; then
  cli_version="$DURABLE_WORKFLOW_CLI_VERSION"
elif [[ -n "$cli_pin" ]]; then
  cli_version="$(semantic_version_from_text "$cli_pin")"
  cli_version="${cli_version:-$default_cli_version}"
else
  cli_version="$default_cli_version"
fi
if [[ -z "$cli_pin" ]]; then
  cli_pin="durable-workflow/cli:${cli_version}"
fi

python_sdk_version="${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:-$default_python_sdk_version}"

workflow_pin="${DURABLE_WORKFLOW_PHP_SDK_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_PHP_SDK_VERSION:-}" ]]; then
  workflow_version="$DURABLE_WORKFLOW_PHP_SDK_VERSION"
elif [[ -n "$workflow_pin" ]]; then
  workflow_version="$(semantic_version_from_text "$workflow_pin")"
  workflow_version="${workflow_version:-$default_workflow_version}"
else
  workflow_version="$default_workflow_version"
fi
if [[ -z "$workflow_pin" ]]; then
  workflow_pin="durable-workflow/workflow:${workflow_version}"
fi

waterline_pin="${DURABLE_WORKFLOW_WATERLINE_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_WATERLINE_VERSION:-}" ]]; then
  waterline_version="$DURABLE_WORKFLOW_WATERLINE_VERSION"
elif [[ -n "$waterline_pin" ]]; then
  waterline_version="$(semantic_version_from_text "$waterline_pin")"
  waterline_version="${waterline_version:-$default_waterline_version}"
else
  waterline_version="$default_waterline_version"
fi
if [[ -z "$waterline_pin" ]]; then
  waterline_pin="durable-workflow/waterline:${waterline_version}"
fi

emit_assignment DURABLE_SERVER_IMAGE "$server_image"
emit_assignment DURABLE_SERVER_VERSION "$server_version"
emit_assignment DURABLE_WORKFLOW_CLI_VERSION "$cli_version"
emit_assignment DURABLE_WORKFLOW_CLI_PIN "$cli_pin"
emit_assignment DURABLE_WORKFLOW_PYTHON_SDK_VERSION "$python_sdk_version"
emit_assignment DURABLE_WORKFLOW_PHP_SDK_VERSION "$workflow_version"
emit_assignment DURABLE_WORKFLOW_PHP_SDK_PIN "$workflow_pin"
emit_assignment DURABLE_WORKFLOW_WATERLINE_VERSION "$waterline_version"
emit_assignment DURABLE_WORKFLOW_WATERLINE_PIN "$waterline_pin"
