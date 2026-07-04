#!/usr/bin/env bash
set -euo pipefail

pinned_server_image="durableworkflow/server:0.2.546"
pinned_cli_version="0.1.85"
pinned_python_sdk_version="0.4.93"
pinned_workflow_version="2.0.0-alpha.244"
pinned_waterline_version="2.0.0-alpha.119"
current_artifact_tuple_url="${DURABLE_WORKFLOW_CURRENT_ARTIFACT_TUPLE_URL:-https://durable-workflow.com/docs-page-release-audit.json}"
waterline_catalog_url="${DURABLE_WORKFLOW_WATERLINE_CATALOG_URL:-https://repo.packagist.org/p2/durable-workflow/waterline.json}"

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

  const requirements = {
    server: /^0\.2\.\d+$/,
    cli: /^0\.1\.\d+$/,
    "sdk-python": /^0\.4\.\d+$/,
    workflow: /^2\.0\.0-(?:alpha|beta)\.\d+$/,
    waterline: /^2\.0\.0-(?:alpha|beta)\.\d+$/,
  };
  const keys = Object.keys(requirements);
  const unknown = Object.keys(artifacts).filter(key => !requirements[key]).sort();
  if (unknown.length > 0) {
    throw new Error(`${label} contains unknown artifact keys: ${unknown.join(", ")}`);
  }

  for (const key of keys) {
    const version = artifacts[key];
    if (typeof version !== "string" || version.trim() !== version || !requirements[key].test(version)) {
      throw new Error(`${label} artifact ${key} has unsupported version ${JSON.stringify(version)}`);
    }
  }

  process.stdout.write(keys.map(key => `${key}=${artifacts[key]}`).join("\n") + "\n");
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
      sdk-python)
        current_python_sdk_version="$version"
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

latest_waterline_prerelease_version() {
  local latest

  require_command curl "resolve durable-workflow/waterline from Packagist"
  require_command node "select the current durable-workflow/waterline prerelease"

  if ! latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 20 "$waterline_catalog_url" \
      | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  const payload = JSON.parse(raw);
  const versions = (((payload || {}).packages || {})["durable-workflow/waterline"] || [])
    .map(candidate => typeof candidate.version === "string" ? candidate.version : "")
    .map(version => {
      const match = /^2\.0\.0-(alpha|beta)\.(\d+)$/.exec(version);
      return match ? { version, channel: match[1] === "beta" ? 1 : 0, ordinal: Number(match[2]) } : null;
    })
    .filter(Boolean)
    .sort((a, b) => (b.channel - a.channel) || (b.ordinal - a.ordinal));

  if (versions.length === 0) {
    process.exit(1);
  }

  process.stdout.write(versions[0].version);
});
'
  )" || ! [[ "$latest" =~ ^2\.0\.0-(alpha|beta)\.[0-9]+$ ]]; then
    printf 'resolve-current-artifacts: unable to resolve current durable-workflow/waterline prerelease from %s\n' "$waterline_catalog_url" >&2
    exit 1
  fi

  printf '%s\n' "$latest"
}

is_newer_prerelease() {
  local candidate="$1"
  local current="$2"
  local candidate_channel
  local current_channel
  local candidate_rank
  local current_rank
  local candidate_ordinal
  local current_ordinal

  if ! [[ "$candidate" =~ ^2\.0\.0-(alpha|beta)\.([0-9]+)$ ]]; then
    return 1
  fi
  candidate_channel="${BASH_REMATCH[1]}"
  candidate_ordinal="${BASH_REMATCH[2]}"

  if ! [[ "$current" =~ ^2\.0\.0-(alpha|beta)\.([0-9]+)$ ]]; then
    return 0
  fi
  current_channel="${BASH_REMATCH[1]}"
  current_ordinal="${BASH_REMATCH[2]}"

  candidate_rank=0
  current_rank=0
  [[ "$candidate_channel" == "beta" ]] && candidate_rank=1
  [[ "$current_channel" == "beta" ]] && current_rank=1

  (( candidate_rank > current_rank )) \
    || (( candidate_rank == current_rank && candidate_ordinal > current_ordinal ))
}

is_newer_stable_version() {
  local candidate="$1"
  local current="$2"
  local candidate_major
  local candidate_minor
  local candidate_patch
  local current_major
  local current_minor
  local current_patch

  if ! [[ "$candidate" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    return 1
  fi
  candidate_major="${BASH_REMATCH[1]}"
  candidate_minor="${BASH_REMATCH[2]}"
  candidate_patch="${BASH_REMATCH[3]}"

  if ! [[ "$current" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    return 0
  fi
  current_major="${BASH_REMATCH[1]}"
  current_minor="${BASH_REMATCH[2]}"
  current_patch="${BASH_REMATCH[3]}"

  (( candidate_major > current_major )) \
    || (( candidate_major == current_major && candidate_minor > current_minor )) \
    || (( candidate_major == current_major && candidate_minor == current_minor && candidate_patch > current_patch ))
}

advance_waterline_from_public_catalog() {
  local latest_waterline_version

  latest_waterline_version="$(latest_waterline_prerelease_version)"
  if is_newer_prerelease "$latest_waterline_version" "$current_waterline_version"; then
    current_waterline_version="$latest_waterline_version"
  fi
}

apply_committed_tuple_floor() {
  local pinned_server_version

  pinned_server_version="$(semantic_version_from_text "$pinned_server_image")"

  if is_newer_stable_version "$pinned_server_version" "$current_server_version"; then
    current_server_version="$pinned_server_version"
  fi
  if is_newer_stable_version "$pinned_cli_version" "$current_cli_version"; then
    current_cli_version="$pinned_cli_version"
  fi
  if is_newer_stable_version "$pinned_python_sdk_version" "$current_python_sdk_version"; then
    current_python_sdk_version="$pinned_python_sdk_version"
  fi
  if is_newer_prerelease "$pinned_workflow_version" "$current_workflow_version"; then
    current_workflow_version="$pinned_workflow_version"
  fi
  if is_newer_prerelease "$pinned_waterline_version" "$current_waterline_version"; then
    current_waterline_version="$pinned_waterline_version"
  fi
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
current_python_sdk_version=""
current_workflow_version=""
current_waterline_version=""

if [[ "$artifact_source" == "pinned" ]]; then
  current_server_version="$(semantic_version_from_text "$pinned_server_image")"
  current_cli_version="$pinned_cli_version"
  current_python_sdk_version="$pinned_python_sdk_version"
  current_workflow_version="$pinned_workflow_version"
  current_waterline_version="$pinned_waterline_version"
elif [[ -n "${DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE:-}" ]]; then
  load_artifact_tuple_file "$DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE"
elif [[ -n "${DURABLE_WORKFLOW_ARTIFACT_TUPLE_URL:-}" ]]; then
  load_artifact_tuple_url "$DURABLE_WORKFLOW_ARTIFACT_TUPLE_URL"
else
  load_artifact_tuple_url "$current_artifact_tuple_url"
  advance_waterline_from_public_catalog
  apply_committed_tuple_floor
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

workflow_pin="${DURABLE_WORKFLOW_PHP_SDK_PIN:-}"
if [[ -n "${DURABLE_WORKFLOW_PHP_SDK_VERSION:-}" ]]; then
  workflow_version="$DURABLE_WORKFLOW_PHP_SDK_VERSION"
elif [[ -n "$workflow_pin" ]]; then
  workflow_version="$(semantic_version_from_text "$workflow_pin")"
  workflow_version="${workflow_version:-$current_workflow_version}"
else
  workflow_version="$current_workflow_version"
fi
if [[ -z "$workflow_pin" ]]; then
  workflow_pin="durable-workflow/workflow:${workflow_version}"
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
  waterline_pin="durable-workflow/waterline:${waterline_version}"
fi

for name in \
  server_image server_version cli_version cli_pin python_sdk_version workflow_version workflow_pin waterline_version waterline_pin
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
emit_assignment DURABLE_WORKFLOW_PYTHON_SDK_VERSION "$python_sdk_version"
emit_assignment DURABLE_WORKFLOW_PHP_SDK_VERSION "$workflow_version"
emit_assignment DURABLE_WORKFLOW_PHP_SDK_PIN "$workflow_pin"
emit_assignment DURABLE_WORKFLOW_WATERLINE_VERSION "$waterline_version"
emit_assignment DURABLE_WORKFLOW_WATERLINE_PIN "$waterline_pin"
