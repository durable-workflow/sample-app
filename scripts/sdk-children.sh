#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-children.sh' \
    'Runs nine PHP/Python/Rust child directions and five Rust-involving typed failure, recovery and cancellation directions.' \
    'Requires original cooperative requester attribution despite forged metadata, duplicates and cold cleanup recovery.' \
    'Requires Docker Compose and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_CHILDREN_COMPOSE_PROJECT_NAME selects an isolated project. All project resources are removed on exit.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_CHILDREN_COMPOSE_PROJECT_NAME:-sample-app-sdk-children-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export DURABLE_WORKFLOW_CHILD_ID="$COMPOSE_PROJECT_NAME"
export CHILD_UID="$(id -u)" CHILD_GID="$(id -g)"
export CHILD_PROOF_DIR="$(mktemp -d "${TMPDIR:-/tmp}/sdk-children.XXXXXX")"
compose=(docker compose --project-directory "$repo_root/polyglot" \
  -f "$repo_root/polyglot/docker-compose.yml" -f "$repo_root/polyglot/docker-compose.children.yml")
workers=(php-same-workflow-worker python-workflow-worker rust-workflow-worker)
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  rmdir "$CHILD_PROOF_DIR"
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi

cleanup() {
  local code=$?
  if [[ "$code" != 0 ]]; then "${compose[@]}" logs --no-color --timestamps || true; fi
  if [[ -n "${SDK_CHILDREN_RESULT_DIR:-}" ]]; then
    mkdir -p "$SDK_CHILDREN_RESULT_DIR"
    cp -R "$CHILD_PROOF_DIR/." "$SDK_CHILDREN_RESULT_DIR/"
  fi
  "${compose[@]}" down --volumes --remove-orphans || return 1
  local suffix image
  for suffix in php-sdk-worker python-workflow-worker rust-workflow-worker smoke; do
    image="${COMPOSE_PROJECT_NAME}-${suffix}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then docker image rm --no-prune "$image" || return 1; fi
  done
  find "$CHILD_PROOF_DIR" -xdev -depth -delete
  return "$code"
}
trap cleanup EXIT

printf 'SDK children start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'Disposable proof directory: %s\n' "$CHILD_PROOF_DIR"
printf 'Runner commit: %s\n' "$(git -C "$repo_root" rev-parse HEAD)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke "${workers[@]}"
"${compose[@]}" run --rm --no-deps --user "$CHILD_UID:$CHILD_GID" smoke \
  python -m unittest discover -s /app/scripts -p test_cooperative_principals.py
"${compose[@]}" run --rm --no-deps --user "$CHILD_UID:$CHILD_GID" smoke \
  python -m unittest discover -s /app/scripts -p test_principal_gateway.py
"${compose[@]}" pull --policy always bootstrap server timer-queue
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue "${workers[@]}"
"${compose[@]}" up -d --wait --no-build principal-gateway
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'

observer() {
  "${compose[@]}" run --rm --no-deps --user "$CHILD_UID:$CHILD_GID" \
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
export POLYGLOT_TIMER_COOPERATIVE=1
"${compose[@]}" up -d --wait --no-build --force-recreate "${workers[@]}"
for phase in cancellation_start cancellation_request cancellation_park; do
  if ! DURABLE_WORKFLOW_CHILD_RUNS="$(observer "$phase")"; then
    printf '%s\n' "$DURABLE_WORKFLOW_CHILD_RUNS" >&2
    exit 1
  fi
  printf '%s\n' "$DURABLE_WORKFLOW_CHILD_RUNS"
done
inspect_worker() {
  docker inspect "$1" | jq '.[0] | {Id,State:{Pid:.State.Pid,StartedAt:.State.StartedAt,FinishedAt:.State.FinishedAt,ExitCode:.State.ExitCode,OOMKilled:.State.OOMKilled,Running:.State.Running}}'
}
declare -A original_cancel_workers
for worker in "${workers[@]}"; do
  original_cancel_workers[$worker]="$("${compose[@]}" ps -q "$worker")"
  inspect_worker "${original_cancel_workers[$worker]}" > "$CHILD_PROOF_DIR/$worker-before.json"
  jq -e '.State.Running == true and .State.Pid > 0' "$CHILD_PROOF_DIR/$worker-before.json" >/dev/null
done
"${compose[@]}" kill --signal SIGKILL "${workers[@]}"
for worker in "${workers[@]}"; do
  inspect_worker "${original_cancel_workers[$worker]}" > "$CHILD_PROOF_DIR/$worker-killed.json"
  jq -e '.State.ExitCode == 137 and .State.OOMKilled == false and .State.Running == false' \
    "$CHILD_PROOF_DIR/$worker-killed.json" >/dev/null
done
observer cancellation_duplicate
"${compose[@]}" up -d --wait --no-build --force-recreate "${workers[@]}"
for worker in "${workers[@]}"; do
  inspect_worker "$("${compose[@]}" ps -q "$worker")" > "$CHILD_PROOF_DIR/$worker-replacement.json"
  jq -e --arg old "${original_cancel_workers[$worker]}" '.Id != $old and .State.Running == true and .State.Pid > 0' \
    "$CHILD_PROOF_DIR/$worker-replacement.json" >/dev/null
done
observer cancellation_verify
printf 'SDK children pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
