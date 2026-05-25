#!/usr/bin/env bash
set -euo pipefail

default_server_image="durableworkflow/server:0.2.194"
default_cli_version="0.1.67"
default_python_sdk_version="0.4.79"
default_workflow_version="2.0.0-alpha.179"
default_waterline_version="2.0.0-alpha.65"

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

latest_packagist_alpha_version() {
  local package="$1"
  local fallback="$2"
  local latest

  if ! command -v curl >/dev/null 2>&1 || ! command -v node >/dev/null 2>&1; then
    printf '%s\n' "$fallback"
    return 0
  fi

  if latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 15 \
      "https://repo.packagist.org/p2/${package}.json" 2>/dev/null \
      | PACKAGIST_PACKAGE="$package" node -e '
const packageName = process.env.PACKAGIST_PACKAGE;
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  const payload = JSON.parse(raw);
  const versions = (((payload || {}).packages || {})[packageName] || [])
    .map(candidate => typeof candidate.version === "string" ? candidate.version : "")
    .map(version => {
      const match = /^2\.0\.0-alpha\.(\d+)$/.exec(version);
      return match ? { version, ordinal: Number(match[1]) } : null;
    })
    .filter(Boolean)
    .sort((a, b) => b.ordinal - a.ordinal);

  if (versions.length === 0) {
    process.exit(1);
  }

  process.stdout.write(versions[0].version);
});
' 2>/dev/null
  )" && [[ "$latest" =~ ^2\.0\.0-alpha\.[0-9]+$ ]]; then
    printf '%s\n' "$latest"
    return 0
  fi

  printf '%s\n' "$fallback"
}

latest_dockerhub_server_image() {
  local fallback="$1"
  local repository="${fallback%:*}"
  local latest

  if ! command -v curl >/dev/null 2>&1 || ! command -v node >/dev/null 2>&1; then
    printf '%s\n' "$fallback"
    return 0
  fi

  if latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 15 \
      "https://registry.hub.docker.com/v2/repositories/${repository}/tags?page_size=100" 2>/dev/null \
      | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  const payload = JSON.parse(raw);
  const tags = Array.isArray(payload.results) ? payload.results : [];
  const versions = tags
    .map(tag => typeof tag.name === "string" ? tag.name : "")
    .map(name => {
      const match = /^0\.2\.(\d+)$/.exec(name);
      return match ? { name, patch: Number(match[1]) } : null;
    })
    .filter(Boolean)
    .sort((a, b) => b.patch - a.patch);

  if (versions.length === 0) {
    process.exit(1);
  }

  process.stdout.write(versions[0].name);
});
' 2>/dev/null
  )" && [[ "$latest" =~ ^0\.2\.[0-9]+$ ]]; then
    printf '%s:%s\n' "$repository" "$latest"
    return 0
  fi

  printf '%s\n' "$fallback"
}

latest_pypi_version() {
  local package="$1"
  local fallback="$2"
  local latest

  if ! command -v curl >/dev/null 2>&1 || ! command -v node >/dev/null 2>&1; then
    printf '%s\n' "$fallback"
    return 0
  fi

  if latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 15 \
      "https://pypi.org/pypi/${package}/json" 2>/dev/null \
      | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  const payload = JSON.parse(raw);
  const releases = payload && payload.releases && typeof payload.releases === "object"
    ? Object.keys(payload.releases)
    : [];
  const versions = releases
    .map(version => {
      const match = /^0\.4\.(\d+)$/.exec(version);
      return match ? { version, patch: Number(match[1]) } : null;
    })
    .filter(Boolean)
    .sort((a, b) => b.patch - a.patch);

  if (versions.length === 0) {
    process.exit(1);
  }

  process.stdout.write(versions[0].version);
});
' 2>/dev/null
  )" && [[ "$latest" =~ ^0\.4\.[0-9]+$ ]]; then
    printf '%s\n' "$latest"
    return 0
  fi

  printf '%s\n' "$fallback"
}

normalize_cli_pin() {
  local pin="$1"
  local version="$2"

  if [[ -z "$pin" ]]; then
    printf 'dw==%s\n' "$version"
    return 0
  fi

  # Older conformance metadata used a Composer-shaped package pin for the
  # CLI. The CLI is installed through its release installer, so normalize the
  # project-owned legacy shape to the resolver-safe binary pin while keeping
  # arbitrary explicit overrides intact.
  if [[ "$pin" =~ ^durable-workflow/cli:([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?)$ ]]; then
    printf 'dw==%s\n' "${BASH_REMATCH[1]}"
    return 0
  fi

  printf '%s\n' "$pin"
}

server_image="${DURABLE_SERVER_IMAGE:-$(latest_dockerhub_server_image "$default_server_image")}"
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
cli_pin="$(normalize_cli_pin "$cli_pin" "$cli_version")"

python_sdk_version="${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:-$(latest_pypi_version durable-workflow "$default_python_sdk_version")}"

workflow_pin="${DURABLE_WORKFLOW_PHP_SDK_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_PHP_SDK_VERSION:-}" ]]; then
  workflow_version="$DURABLE_WORKFLOW_PHP_SDK_VERSION"
elif [[ -n "$workflow_pin" ]]; then
  workflow_version="$(semantic_version_from_text "$workflow_pin")"
  workflow_version="${workflow_version:-$default_workflow_version}"
else
  workflow_version="$(latest_packagist_alpha_version durable-workflow/workflow "$default_workflow_version")"
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
  waterline_version="$(latest_packagist_alpha_version durable-workflow/waterline "$default_waterline_version")"
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
