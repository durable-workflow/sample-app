#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tuple_file="${DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE:-${repo_root}/polyglot/qualified-artifact-tuple.json}"

if [[ $# -ne 0 ]]; then
  printf 'resolve-current-artifacts: this command does not accept arguments\n' >&2
  exit 1
fi

if [[ ! -f "$tuple_file" ]]; then
  printf 'resolve-current-artifacts: artifact tuple not found: %s\n' "$tuple_file" >&2
  exit 1
fi

if ! command -v node >/dev/null 2>&1; then
  printf 'resolve-current-artifacts: node is required to read %s\n' "$tuple_file" >&2
  exit 1
fi

parse_tuple() {
  node - "$tuple_file" <<'NODE'
const fs = require('node:fs');
const path = process.argv[2];
const expectedSchema = 'durable-workflow.sample-app.polyglot-qualified-artifact-tuple';
const keys = ['server', 'cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'workflow', 'waterline'];
const stableV2 = /^2\.\d+\.\d+(?:\+[0-9A-Za-z.-]+)?$/;

let tuple;
try {
  tuple = JSON.parse(fs.readFileSync(path, 'utf8'));
} catch (error) {
  throw new Error(`${path} is not valid JSON: ${error.message}`);
}

if (tuple?.schema !== expectedSchema || tuple?.schemaVersion !== 1) {
  throw new Error(`${path} is not a supported Sample App artifact tuple`);
}

const artifacts = tuple.artifacts;
if (!artifacts || typeof artifacts !== 'object' || Array.isArray(artifacts)) {
  throw new Error(`${path} does not contain an artifacts object`);
}

const unknown = Object.keys(artifacts).filter(key => !keys.includes(key));
if (unknown.length > 0) {
  throw new Error(`${path} contains unknown artifacts: ${unknown.sort().join(', ')}`);
}

for (const key of keys) {
  const version = artifacts[key];
  if (typeof version !== 'string' || !stableV2.test(version)) {
    throw new Error(`${path} artifact ${key} must be a stable 2.x version`);
  }
  process.stdout.write(`${key}=${version}\n`);
}
NODE
}

declare -A tuple=()
while IFS='=' read -r artifact version; do
  tuple["$artifact"]="$version"
done < <(parse_tuple)

stable_version() {
  local name="$1"
  local value="$2"

  if [[ ! "$value" =~ ^2\.[0-9]+\.[0-9]+(\+[0-9A-Za-z.-]+)?$ ]]; then
    printf 'resolve-current-artifacts: %s must be a stable 2.x version; received %s\n' \
      "$name" "$value" >&2
    exit 1
  fi

  printf '%s\n' "$value"
}

version_from_pin() {
  local pin="$1"
  if [[ "$pin" =~ ([0-9]+\.[0-9]+\.[0-9]+(\+[0-9A-Za-z.-]+)?) ]]; then
    printf '%s\n' "${BASH_REMATCH[1]}"
  fi
}

emit() {
  local name="$1"
  local value="$2"

  if [[ -z "$value" || "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    printf 'resolve-current-artifacts: invalid value for %s\n' "$name" >&2
    exit 1
  fi

  printf '%s=%s\n' "$name" "$value"
}

server_version="${SAMPLE_APP_SERVER_VERSION:-${tuple[server]}}"
server_image="${SAMPLE_APP_SERVER_IMAGE:-durableworkflow/server:${server_version}}"
if [[ -z "${SAMPLE_APP_SERVER_VERSION:-}" ]]; then
  detected_server_version="$(version_from_pin "$server_image")"
  server_version="${detected_server_version:-$server_version}"
fi
server_version="$(stable_version DURABLE_SERVER_VERSION "$server_version")"

cli_pin="${SAMPLE_APP_CLI_PIN:-}"
cli_version="${SAMPLE_APP_CLI_VERSION:-}"
if [[ -z "$cli_version" && -n "$cli_pin" ]]; then
  cli_version="$(version_from_pin "$cli_pin")"
fi
cli_version="$(stable_version DURABLE_WORKFLOW_CLI_VERSION "${cli_version:-${tuple[cli]}}")"
cli_pin="${cli_pin:-dw==${cli_version}}"

php_pin="${SAMPLE_APP_PHP_SDK_PIN:-}"
php_version="${SAMPLE_APP_PHP_SDK_VERSION:-}"
if [[ -z "$php_version" && -n "$php_pin" ]]; then
  php_version="$(version_from_pin "$php_pin")"
fi
php_version="$(stable_version DURABLE_WORKFLOW_PHP_SDK_VERSION "${php_version:-${tuple[sdk-php]}}")"
php_pin="${php_pin:-durable-workflow/sdk:${php_version}}"

python_version="$(stable_version SAMPLE_APP_PYTHON_SDK_VERSION \
  "${SAMPLE_APP_PYTHON_SDK_VERSION:-${tuple[sdk-python]}}")"
rust_version="$(stable_version SAMPLE_APP_RUST_SDK_VERSION \
  "${SAMPLE_APP_RUST_SDK_VERSION:-${tuple[sdk-rust]}}")"

workflow_pin="${SAMPLE_APP_WORKFLOW_PIN:-}"
workflow_version="${SAMPLE_APP_WORKFLOW_VERSION:-}"
if [[ -z "$workflow_version" && -n "$workflow_pin" ]]; then
  workflow_version="$(version_from_pin "$workflow_pin")"
fi
workflow_version="$(stable_version DURABLE_WORKFLOW_WORKFLOW_VERSION \
  "${workflow_version:-${tuple[workflow]}}")"
workflow_pin="${workflow_pin:-durable-workflow/workflow:${workflow_version}}"

waterline_pin="${SAMPLE_APP_WATERLINE_PIN:-}"
waterline_version="${SAMPLE_APP_WATERLINE_VERSION:-}"
if [[ -z "$waterline_version" && -n "$waterline_pin" ]]; then
  waterline_version="$(version_from_pin "$waterline_pin")"
fi
waterline_version="$(stable_version DURABLE_WORKFLOW_WATERLINE_VERSION \
  "${waterline_version:-${tuple[waterline]}}")"
waterline_pin="${waterline_pin:-durable-workflow/waterline:${waterline_version}}"

emit DURABLE_SERVER_IMAGE "$server_image"
emit DURABLE_SERVER_VERSION "$server_version"
emit DURABLE_WORKFLOW_CLI_VERSION "$cli_version"
emit DURABLE_WORKFLOW_CLI_PIN "$cli_pin"
emit DURABLE_WORKFLOW_PHP_SDK_VERSION "$php_version"
emit DURABLE_WORKFLOW_PHP_SDK_PIN "$php_pin"
emit DURABLE_WORKFLOW_PYTHON_SDK_VERSION "$python_version"
emit DURABLE_WORKFLOW_RUST_SDK_VERSION "$rust_version"
emit DURABLE_WORKFLOW_WORKFLOW_VERSION "$workflow_version"
emit DURABLE_WORKFLOW_WORKFLOW_PIN "$workflow_pin"
emit DURABLE_WORKFLOW_WATERLINE_VERSION "$waterline_version"
emit DURABLE_WORKFLOW_WATERLINE_PIN "$waterline_pin"
