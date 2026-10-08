#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-search-attributes.sh' \
    'Runs published Rust typed updates with PHP/Python visibility observers and worker SIGKILL/cold replay.' \
    'Requires Docker Compose, jq and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_SEARCH_ATTRIBUTES_COMPOSE_PROJECT_NAME selects an isolated project.' \
    'SDK_SEARCH_ATTRIBUTES_RESULT_DIR optionally retains raw observations outside disposable resources.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_SEARCH_ATTRIBUTES_COMPOSE_PROJECT_NAME:-sample-app-search-attributes-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export SEARCH_ATTRIBUTES_UID="$(id -u)" SEARCH_ATTRIBUTES_GID="$(id -g)"
export SEARCH_ATTRIBUTES_PROOF_DIR="$(mktemp -d "${TMPDIR:-/tmp}/sdk-search-attributes.XXXXXX")"
export SEARCH_ATTRIBUTES_PROOF_DIR="$(cd "$SEARCH_ATTRIBUTES_PROOF_DIR" && pwd)"
compose=(docker compose --project-directory "$repo_root/polyglot" \
  -f "$repo_root/polyglot/docker-compose.yml" -f "$repo_root/polyglot/docker-compose.search-attributes.yml")
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  rmdir "$SEARCH_ATTRIBUTES_PROOF_DIR"
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi
cleanup() {
  local code=$? suffix image
  if [[ "$code" != 0 ]]; then "${compose[@]}" logs --no-color --timestamps || true; fi
  if [[ -n "${SDK_SEARCH_ATTRIBUTES_RESULT_DIR:-}" ]]; then
    mkdir -p "$SDK_SEARCH_ATTRIBUTES_RESULT_DIR"
    cp -R "$SEARCH_ATTRIBUTES_PROOF_DIR/." "$SDK_SEARCH_ATTRIBUTES_RESULT_DIR/"
  fi
  "${compose[@]}" down --volumes --remove-orphans || return 1
  for suffix in rust-workflow-worker smoke php-sdk-worker; do
    image="${COMPOSE_PROJECT_NAME}-${suffix}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then docker image rm --no-prune "$image" || return 1; fi
  done
  find "$SEARCH_ATTRIBUTES_PROOF_DIR" -xdev -depth -delete
  return "$code"
}
trap cleanup EXIT
printf 'Search attributes start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'Disposable proof directory: %s\n' "$SEARCH_ATTRIBUTES_PROOF_DIR"
printf 'Runner commit: %s\n' "$(git -C "$repo_root" rev-parse HEAD)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke php-workflow-worker rust-workflow-worker
"${compose[@]}" run --rm --no-deps --user "$SEARCH_ATTRIBUTES_UID:$SEARCH_ATTRIBUTES_GID" \
  smoke python -m unittest discover -s /app/scripts -p test_rust_search_attributes.py
"${compose[@]}" pull --policy always bootstrap server
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'
python_observer() {
  "${compose[@]}" run --rm --no-deps --user "$SEARCH_ATTRIBUTES_UID:$SEARCH_ATTRIBUTES_GID" smoke python /app/scripts/rust_search_attributes.py "$1"
}
php_observer() {
  "${compose[@]}" run --rm --no-deps --user "$SEARCH_ATTRIBUTES_UID:$SEARCH_ATTRIBUTES_GID" php-workflow-worker php /app/rust_search_attributes.php "$1"
}
inspect_worker() {
  docker inspect "$1" | jq '.[0] | {Id,State:{Pid:.State.Pid,StartedAt:.State.StartedAt,FinishedAt:.State.FinishedAt,ExitCode:.State.ExitCode,OOMKilled:.State.OOMKilled,Running:.State.Running}}'
}
python_observer setup
"${compose[@]}" up -d --wait --no-build rust-search-worker
python_observer park
php_observer park
old_worker="$("${compose[@]}" ps -q rust-search-worker)"
inspect_worker "$old_worker" > "$SEARCH_ATTRIBUTES_PROOF_DIR/worker-before.json"
jq -e '.State.Running == true and .State.Pid > 0' "$SEARCH_ATTRIBUTES_PROOF_DIR/worker-before.json" >/dev/null
"${compose[@]}" kill --signal SIGKILL rust-search-worker
inspect_worker "$old_worker" > "$SEARCH_ATTRIBUTES_PROOF_DIR/worker-killed.json"
jq -e '.State.ExitCode == 137 and .State.OOMKilled == false and .State.Running == false' \
  "$SEARCH_ATTRIBUTES_PROOF_DIR/worker-killed.json" >/dev/null
python_observer release
"${compose[@]}" up -d --wait --no-build --force-recreate rust-search-worker
new_worker="$("${compose[@]}" ps -q rust-search-worker)"
inspect_worker "$new_worker" > "$SEARCH_ATTRIBUTES_PROOF_DIR/worker-replacement.json"
jq -e --arg old "$old_worker" '.Id != $old and .State.Running == true and .State.Pid > 0' \
  "$SEARCH_ATTRIBUTES_PROOF_DIR/worker-replacement.json" >/dev/null
python_observer verify
php_observer verify
printf 'Search attributes pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
