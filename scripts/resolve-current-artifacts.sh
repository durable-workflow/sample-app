#!/usr/bin/env bash
set -euo pipefail

pinned_server_image="durableworkflow/server:0.2.400"
pinned_cli_version="0.1.80"
pinned_python_sdk_version="0.4.88"
pinned_workflow_version="2.0.0-alpha.204"
pinned_waterline_version="2.0.0-alpha.87"

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

latest_packagist_prerelease_version() {
  local package="$1"
  local latest

  require_command curl "resolve ${package} from Packagist"
  require_command node "select the latest ${package} prerelease"

  if ! latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 20 \
      "https://repo.packagist.org/p2/${package}.json" \
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
    printf 'resolve-current-artifacts: unable to resolve latest Packagist prerelease for %s\n' "$package" >&2
    exit 1
  fi

  printf '%s\n' "$latest"
}

latest_dockerhub_server_version() {
  local latest

  require_command curl "resolve durableworkflow/server from Docker Hub"
  require_command node "select the latest server image tag"

  if ! latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 20 \
      "https://registry.hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100" \
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
'
  )" || ! [[ "$latest" =~ ^0\.2\.[0-9]+$ ]]; then
    printf 'resolve-current-artifacts: unable to resolve latest durableworkflow/server tag\n' >&2
    exit 1
  fi

  printf '%s\n' "$latest"
}

latest_github_release_version() {
  local repo="$1"
  local latest

  require_command curl "resolve ${repo} from GitHub releases"
  require_command node "select the latest ${repo} release"

  if ! latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 20 \
      -H 'Accept: application/vnd.github+json' \
      "https://api.github.com/repos/${repo}/releases/latest" \
      | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  const payload = JSON.parse(raw);
  const tag = typeof payload.tag_name === "string" ? payload.tag_name.replace(/^v/, "") : "";
  if (!/^0\.1\.\d+$/.test(tag)) {
    process.exit(1);
  }

  process.stdout.write(tag);
});
'
  )" || ! [[ "$latest" =~ ^0\.1\.[0-9]+$ ]]; then
    printf 'resolve-current-artifacts: unable to resolve latest GitHub release for %s\n' "$repo" >&2
    exit 1
  fi

  printf '%s\n' "$latest"
}

latest_pypi_version() {
  local package="$1"
  local latest

  require_command curl "resolve ${package} from PyPI"
  require_command node "select the latest ${package} release"

  if ! latest="$(
    curl -fsSL --retry 2 --connect-timeout 5 --max-time 20 \
      "https://pypi.org/pypi/${package}/json" \
      | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => { raw += chunk; });
process.stdin.on("end", () => {
  const payload = JSON.parse(raw);
  const releases = payload && payload.releases && typeof payload.releases === "object"
    ? Object.entries(payload.releases)
    : [];
  const versions = releases
    .filter(([, files]) => !Array.isArray(files) || files.some(file => !file.yanked))
    .map(([version]) => {
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
'
  )" || ! [[ "$latest" =~ ^0\.4\.[0-9]+$ ]]; then
    printf 'resolve-current-artifacts: unable to resolve latest PyPI release for %s\n' "$package" >&2
    exit 1
  fi

  printf '%s\n' "$latest"
}

resolve_published_tuple() {
  current_server_version="$(latest_dockerhub_server_version)"
  current_cli_version="$(latest_github_release_version durable-workflow/cli)"
  current_python_sdk_version="$(latest_pypi_version durable-workflow)"
  current_workflow_version="$(latest_packagist_prerelease_version durable-workflow/workflow)"
  current_waterline_version="$(latest_packagist_prerelease_version durable-workflow/waterline)"
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
  resolve_published_tuple
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
