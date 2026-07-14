#!/usr/bin/env bash
set -euo pipefail

install_flags=(
  --no-dev
  --no-scripts
  --no-autoloader
  --prefer-dist
  --no-interaction
)

update_flags=(
  --with-dependencies
  --no-dev
  --no-scripts
  --no-autoloader
  --prefer-dist
  --no-interaction
)

artifact_constraint_from_pin() {
  local pin="$1"
  local package="$2"
  local version="$3"

  if [[ -n "$pin" ]]; then
    version="${pin#${package}:}"
  fi

  printf '%s\n' "${version%@*}"
}

locked_package_version() {
  local package="$1"

  php -r '
$package = $argv[1] ?? "";
$lockPath = "composer.lock";

if (! is_file($lockPath)) {
    exit(1);
}

$lock = json_decode((string) file_get_contents($lockPath), true);
if (! is_array($lock)) {
    exit(1);
}

foreach (($lock["packages"] ?? []) as $candidate) {
    if (($candidate["name"] ?? null) === $package) {
        $version = $candidate["version"] ?? "";

        if (is_string($version) && $version !== "") {
            echo $version;
            exit(0);
        }

        exit(1);
    }
}

exit(1);
' "$package"
}

workflow_constraint="$(
  artifact_constraint_from_pin \
    "${DURABLE_WORKFLOW_WORKFLOW_PIN:-}" \
    durable-workflow/workflow \
    "${DURABLE_WORKFLOW_WORKFLOW_VERSION:-}"
)"
waterline_constraint="$(
  artifact_constraint_from_pin \
    "${DURABLE_WORKFLOW_WATERLINE_PIN:-}" \
    durable-workflow/waterline \
    "${DURABLE_WORKFLOW_WATERLINE_VERSION:-}"
)"

if [[ -z "$workflow_constraint" && -z "$waterline_constraint" ]]; then
  composer install "${install_flags[@]}"
  exit 0
fi

test -n "$workflow_constraint"
test -n "$waterline_constraint"

locked_workflow_version="$(locked_package_version durable-workflow/workflow || true)"
locked_waterline_version="$(locked_package_version durable-workflow/waterline || true)"

if [[ "$locked_workflow_version" == "$workflow_constraint" && "$locked_waterline_version" == "$waterline_constraint" ]]; then
  composer install "${install_flags[@]}"
  exit 0
fi

composer require --no-update \
  "durable-workflow/workflow:${workflow_constraint}" \
  "durable-workflow/waterline:${waterline_constraint}"

composer update durable-workflow/workflow durable-workflow/waterline \
  "${update_flags[@]}"
