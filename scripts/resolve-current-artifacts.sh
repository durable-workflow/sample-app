#!/usr/bin/env bash
set -euo pipefail

pinned_server_image="durableworkflow/server:2.0.0-beta.5"
pinned_cli_version="2.0.0-beta.5"
pinned_php_sdk_version="2.0.0-beta.5"
pinned_python_sdk_version="2.0.0-beta.5"
pinned_rust_sdk_version="2.0.0-beta.5"
pinned_workflow_version="2.0.0-beta.5"
pinned_waterline_version="2.0.0-beta.5"
current_artifact_tuple_url="${DURABLE_WORKFLOW_CURRENT_ARTIFACT_TUPLE_URL:-https://durable-workflow.com/docs-page-release-audit.json}"

artifact_source="${DURABLE_WORKFLOW_ARTIFACT_SOURCE:-current}"
legacy_resolve_latest="${DURABLE_WORKFLOW_RESOLVE_LATEST:-}"

is_truthy() {
  local value="${1:-}"
  [[ "$value" == "1" || "$value" == "true" || "$value" == "yes" ]]
}

if [[ -z "${DURABLE_WORKFLOW_ARTIFACT_SOURCE:-}" ]] && is_truthy "$legacy_resolve_latest"; then
  artifact_source="current"
fi

case "$artifact_source" in
  current|published|latest)
    artifact_source="current"
    ;;
  pinned|static|locked)
    artifact_source="pinned"
    ;;
  *)
    printf 'resolve-current-artifacts: unsupported DURABLE_WORKFLOW_ARTIFACT_SOURCE=%s (expected current or pinned)\n' "$artifact_source" >&2
    exit 1
    ;;
esac

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

require_command() {
  local name="$1"
  local purpose="$2"

  if ! command -v "$name" >/dev/null 2>&1; then
    printf 'resolve-current-artifacts: %s is required to %s\n' "$name" "$purpose" >&2
    exit 1
  fi
}

parse_artifact_tuple_json() {
  local label="$1"

  require_command node "parse the current artifact tuple JSON"

  node -e '
const label = process.argv[1] || "artifact tuple";
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  let payload;
  try {
    payload = JSON.parse(raw);
  } catch (error) {
    throw new Error(`${label} is not valid JSON: ${error.message}`);
  }

  let artifacts = null;
  if (payload && payload.schema === "durable-workflow.docs.page-release-audit") {
    artifacts = payload.artifact_versions;
  } else if (payload && payload.schema === "durable-workflow.docs.public-artifact-versions") {
    artifacts = payload.artifacts;
  } else if (payload && payload.artifact_versions && typeof payload.artifact_versions === "object") {
    artifacts = payload.artifact_versions;
  } else if (payload && payload.artifacts && typeof payload.artifacts === "object") {
    artifacts = payload.artifacts;
  }

  if (!artifacts || typeof artifacts !== "object" || Array.isArray(artifacts)) {
    throw new Error(`${label} must expose artifact_versions or artifacts`);
  }

  const supportedTrainPattern = /^2\.0\.0-beta\.\d+$/;
  const officialRequirements = Object.fromEntries(
    ["server", "cli", "sdk-php", "sdk-python", "sdk-rust", "workflow", "waterline"]
      .map(key => [key, supportedTrainPattern]),
  );
  const emittedArtifacts = ["server", "cli", "sdk-php", "sdk-python", "sdk-rust", "workflow", "waterline"];
  const unknown = Object.keys(artifacts).filter(key => !officialRequirements[key]).sort();
  if (unknown.length > 0) {
    throw new Error(`${label} contains unknown artifact keys: ${unknown.join(", ")}`);
  }

  for (const key of emittedArtifacts) {
    const requirement = officialRequirements[key];
    const version = artifacts[key];
    if (typeof version !== "string" || version.trim() !== version || !requirement.test(version)) {
      throw new Error(`${label} artifact ${key} has unsupported version ${JSON.stringify(version)}`);
    }
  }

  const versions = new Set(emittedArtifacts.map(key => artifacts[key]));
  if (versions.size !== 1) {
    throw new Error(`${label} must expose one synchronized 2.0 beta version across every artifact`);
  }

  process.stdout.write(emittedArtifacts.map(key => `${key}=${artifacts[key]}`).join("\n") + "\n");
});
' "$label"
}

load_artifact_tuple_assignments() {
  local assignments="$1"
  local artifact
  local version

  while IFS='=' read -r artifact version; do
    case "$artifact" in
      server)
        current_server_version="$version"
        ;;
      cli)
        current_cli_version="$version"
        ;;
      sdk-php)
        current_php_sdk_version="$version"
        ;;
      sdk-python)
        current_python_sdk_version="$version"
        ;;
      sdk-rust)
        current_rust_sdk_version="$version"
        ;;
      workflow)
        current_workflow_version="$version"
        ;;
      waterline)
        current_waterline_version="$version"
        ;;
      "")
        ;;
      *)
        printf 'resolve-current-artifacts: unexpected artifact tuple key %s\n' "$artifact" >&2
        exit 1
        ;;
    esac
  done <<< "$assignments"
}

load_artifact_tuple_file() {
  local file="$1"
  local assignments

  if [[ ! -f "$file" ]]; then
    printf 'resolve-current-artifacts: artifact tuple file not found: %s\n' "$file" >&2
    exit 1
  fi

  if ! assignments="$(parse_artifact_tuple_json "$file" < "$file")"; then
    printf 'resolve-current-artifacts: failed to parse artifact tuple file %s\n' "$file" >&2
    exit 1
  fi

  load_artifact_tuple_assignments "$assignments"
}

load_artifact_tuple_url() {
  local url="$1"
  local assignments

  require_command curl "download the current artifact tuple JSON"

  if ! assignments="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 20 "$url" \
      | parse_artifact_tuple_json "$url"
  )"; then
    printf 'resolve-current-artifacts: failed to resolve artifact tuple from %s\n' "$url" >&2
    exit 1
  fi

  load_artifact_tuple_assignments "$assignments"
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

current_server_version=""
current_cli_version=""
current_php_sdk_version=""
current_python_sdk_version=""
current_rust_sdk_version=""
current_workflow_version=""
current_waterline_version=""

if [[ "$artifact_source" == "pinned" ]]; then
  current_server_version="$(semantic_version_from_text "$pinned_server_image")"
  current_cli_version="$pinned_cli_version"
  current_php_sdk_version="$pinned_php_sdk_version"
  current_python_sdk_version="$pinned_python_sdk_version"
  current_rust_sdk_version="$pinned_rust_sdk_version"
  current_workflow_version="$pinned_workflow_version"
  current_waterline_version="$pinned_waterline_version"
elif [[ -n "${DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE:-}" ]]; then
  load_artifact_tuple_file "$DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE"
elif [[ -n "${DURABLE_WORKFLOW_ARTIFACT_TUPLE_URL:-}" ]]; then
  load_artifact_tuple_url "$DURABLE_WORKFLOW_ARTIFACT_TUPLE_URL"
else
  load_artifact_tuple_url "$current_artifact_tuple_url"
fi

server_image="${DURABLE_SERVER_IMAGE:-}"
if [[ -z "$server_image" ]]; then
  server_image="durableworkflow/server:${current_server_version}"
fi
server_version="$(semantic_version_from_text "$server_image")"
server_version="${server_version:-$current_server_version}"

cli_pin="${DURABLE_WORKFLOW_CLI_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_CLI_VERSION:-}" ]]; then
  cli_version="$DURABLE_WORKFLOW_CLI_VERSION"
elif [[ -n "$cli_pin" ]]; then
  cli_version="$(semantic_version_from_text "$cli_pin")"
  cli_version="${cli_version:-$current_cli_version}"
else
  cli_version="$current_cli_version"
fi
cli_pin="$(normalize_cli_pin "$cli_pin" "$cli_version")"

python_sdk_version="${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:-$current_python_sdk_version}"
rust_sdk_version="${DURABLE_WORKFLOW_RUST_SDK_VERSION:-$current_rust_sdk_version}"

php_sdk_pin="${DURABLE_WORKFLOW_PHP_SDK_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_PHP_SDK_VERSION:-}" ]]; then
  php_sdk_version="$DURABLE_WORKFLOW_PHP_SDK_VERSION"
elif [[ -n "$php_sdk_pin" ]]; then
  php_sdk_version="$(semantic_version_from_text "$php_sdk_pin")"
  php_sdk_version="${php_sdk_version:-$current_php_sdk_version}"
else
  php_sdk_version="$current_php_sdk_version"
fi
if [[ -z "$php_sdk_pin" ]]; then
  php_sdk_pin="durable-workflow/sdk:${php_sdk_version}@beta"
fi

workflow_pin="${DURABLE_WORKFLOW_WORKFLOW_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_WORKFLOW_VERSION:-}" ]]; then
  workflow_version="$DURABLE_WORKFLOW_WORKFLOW_VERSION"
elif [[ -n "$workflow_pin" ]]; then
  workflow_version="$(semantic_version_from_text "$workflow_pin")"
  workflow_version="${workflow_version:-$current_workflow_version}"
else
  workflow_version="$current_workflow_version"
fi
if [[ -z "$workflow_pin" ]]; then
  workflow_pin="durable-workflow/workflow:${workflow_version}@beta"
fi

waterline_pin="${DURABLE_WORKFLOW_WATERLINE_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_WATERLINE_VERSION:-}" ]]; then
  waterline_version="$DURABLE_WORKFLOW_WATERLINE_VERSION"
elif [[ -n "$waterline_pin" ]]; then
  waterline_version="$(semantic_version_from_text "$waterline_pin")"
  waterline_version="${waterline_version:-$current_waterline_version}"
else
  waterline_version="$current_waterline_version"
fi
if [[ -z "$waterline_pin" ]]; then
  waterline_pin="durable-workflow/waterline:${waterline_version}@beta"
fi

for name in \
  server_image server_version cli_version cli_pin php_sdk_version php_sdk_pin python_sdk_version rust_sdk_version workflow_version workflow_pin waterline_version waterline_pin
do
  if [[ -z "${!name:-}" ]]; then
    printf 'resolve-current-artifacts: failed to resolve %s from %s artifact source\n' "$name" "$artifact_source" >&2
    exit 1
  fi
done

emit_assignment DURABLE_SERVER_IMAGE "$server_image"
emit_assignment DURABLE_SERVER_VERSION "$server_version"
emit_assignment DURABLE_WORKFLOW_CLI_VERSION "$cli_version"
emit_assignment DURABLE_WORKFLOW_CLI_PIN "$cli_pin"
emit_assignment DURABLE_WORKFLOW_PHP_SDK_VERSION "$php_sdk_version"
emit_assignment DURABLE_WORKFLOW_PHP_SDK_PIN "$php_sdk_pin"
emit_assignment DURABLE_WORKFLOW_PYTHON_SDK_VERSION "$python_sdk_version"
emit_assignment DURABLE_WORKFLOW_RUST_SDK_VERSION "$rust_sdk_version"
emit_assignment DURABLE_WORKFLOW_WORKFLOW_VERSION "$workflow_version"
emit_assignment DURABLE_WORKFLOW_WORKFLOW_PIN "$workflow_pin"
emit_assignment DURABLE_WORKFLOW_WATERLINE_VERSION "$waterline_version"
emit_assignment DURABLE_WORKFLOW_WATERLINE_PIN "$waterline_pin"
