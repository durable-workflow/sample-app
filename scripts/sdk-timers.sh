#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-timers.sh' \
    'Runs completion, worker SIGKILL/cold replay, Server restart and cooperative cancellation for PHP/Python/Rust timers.' \
    'Requires Docker Compose and exact artifact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_TIMERS_COMPOSE_PROJECT_NAME selects an isolated project. All project resources are removed on exit.'
  exit 0
fi
[[ $# == 0 ]] || { printf '%s\n' 'Use --help for usage.' >&2; exit 2; }

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_TIMERS_COMPOSE_PROJECT_NAME:-sample-app-sdk-timers-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export COMPOSE_PROFILES=timers
export POLYGLOT_TIMER_COOPERATIVE=1
export DURABLE_WORKFLOW_TIMER_ID="${COMPOSE_PROJECT_NAME}"
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
    if docker image inspect "$image" >/dev/null 2>&1; then
      docker image rm --no-prune "$image" || return 1
    fi
  done
  return "$code"
}
trap cleanup EXIT

printf 'SDK timers start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke "${workers[@]}"
"${compose[@]}" pull --policy always bootstrap server timer-queue
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue "${workers[@]}"
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'

client() {
  "${compose[@]}" run --rm --no-deps --user 1000:1000 \
    -e DURABLE_WORKFLOW_TIMER_ID -e DURABLE_WORKFLOW_WORKER_STOPPED_AT -e DURABLE_WORKFLOW_WORKER_RESTART_AT \
    -e DURABLE_WORKFLOW_SERVER_STOPPED_AT -e DURABLE_WORKFLOW_SERVER_RESTART_AT \
    -e DURABLE_WORKFLOW_TIMER_PENDING \
    smoke python /app/scripts/sdk_timers.py "$@"
}

start() {
  DURABLE_WORKFLOW_TIMER_PENDING="$(client start "$1")"
  export DURABLE_WORKFLOW_TIMER_PENDING
  printf '%s\n' "$DURABLE_WORKFLOW_TIMER_PENDING"
}

start completion
client verify completion

start worker-restart
"${compose[@]}" kill --signal SIGKILL "${workers[@]}"
export DURABLE_WORKFLOW_WORKER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
client fired worker-restart
export DURABLE_WORKFLOW_WORKER_RESTART_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
"${compose[@]}" up -d --wait --no-build "${workers[@]}"
client verify worker-restart

start server-restart
"${compose[@]}" stop --timeout 2 timer-queue server
export DURABLE_WORKFLOW_SERVER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
sleep 32
export DURABLE_WORKFLOW_SERVER_RESTART_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue
client verify server-restart

start cancellation
client verify cancellation
printf 'SDK timers pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
