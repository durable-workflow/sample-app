#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-children.sh' \
    'Runs nine PHP/Python/Rust child directions and five Rust-involving typed failure/recovery directions.' \
    'Requires Docker Compose and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_CHILDREN_COMPOSE_PROJECT_NAME selects an isolated project. All project resources are removed on exit.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_CHILDREN_COMPOSE_PROJECT_NAME:-sample-app-sdk-children-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export DURABLE_WORKFLOW_CHILD_ID="$COMPOSE_PROJECT_NAME"
compose=(docker compose --project-directory "$repo_root/polyglot" -f "$repo_root/polyglot/docker-compose.yml")
workers=(php-same-workflow-worker python-workflow-worker rust-workflow-worker)
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi

cleanup() {
  local code=$?
  if [[ "$code" != 0 ]]; then "${compose[@]}" logs --no-color --timestamps || true; fi
  "${compose[@]}" down --volumes --remove-orphans || return 1
  local suffix image
  for suffix in php-sdk-worker python-workflow-worker rust-workflow-worker smoke; do
    image="${COMPOSE_PROJECT_NAME}-${suffix}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then docker image rm --no-prune "$image" || return 1; fi
  done
  return "$code"
}
trap cleanup EXIT

printf 'SDK children start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke "${workers[@]}"
"${compose[@]}" pull --policy always bootstrap server
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server "${workers[@]}"
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'

observer() {
  "${compose[@]}" run --rm --no-deps --user 1000:1000 \
    -e DURABLE_WORKFLOW_CHILD_ID -e DURABLE_WORKFLOW_CHILD_RUNS \
    smoke python /app/scripts/sdk_children.py "$@"
}

observer matrix
observer failure
if ! DURABLE_WORKFLOW_CHILD_RUNS="$(observer park)"; then
  printf '%s\n' "$DURABLE_WORKFLOW_CHILD_RUNS" >&2
  exit 1
fi
export DURABLE_WORKFLOW_CHILD_RUNS
printf '%s\n' "$DURABLE_WORKFLOW_CHILD_RUNS"
"${compose[@]}" kill --signal SIGKILL "${workers[@]}"
observer release
"${compose[@]}" up -d --wait --no-build "${workers[@]}"
observer recovery
printf 'SDK children pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
