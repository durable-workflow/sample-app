#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-updates.sh' \
    'Runs nine PHP/Python/Rust client/update-handler directions, state mutation and queries, committed snapshots, all-worker replacement, duplicate requests, handler failure and validator refusal.' \
    'SDK_UPDATES_FINISH_RACE_REPETITIONS=0..20 adds Rust signal completion during update replay.' \
    'Requires Docker Compose and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_UPDATES_COMPOSE_PROJECT_NAME selects an isolated project. All project resources are removed on exit.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
export SDK_UPDATES_FINISH_RACE_REPETITIONS="${SDK_UPDATES_FINISH_RACE_REPETITIONS:-0}"
[[ "$SDK_UPDATES_FINISH_RACE_REPETITIONS" =~ ^([0-9]|1[0-9]|20)$ ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_UPDATES_COMPOSE_PROJECT_NAME:-sample-app-sdk-updates-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export DURABLE_WORKFLOW_UPDATE_ID="$COMPOSE_PROJECT_NAME"
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

printf 'SDK updates start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke "${workers[@]}"
"${compose[@]}" pull --policy always bootstrap server timer-queue
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue "${workers[@]}"
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'

observer() {
  "${compose[@]}" run --rm --no-deps --user 1000:1000 \
    -e DURABLE_WORKFLOW_UPDATE_ID -e DURABLE_WORKFLOW_UPDATE_RUNS \
    -e DURABLE_WORKFLOW_UPDATE_RESULTS -e DURABLE_WORKFLOW_UPDATE_QUEUED \
    -e DURABLE_WORKFLOW_STATE_RESULTS -e DURABLE_WORKFLOW_STATE_QUEUED -e DURABLE_WORKFLOW_STATE_DUPLICATES \
    -e SDK_UPDATES_FINISH_RACE_REPETITIONS \
    smoke python /app/scripts/sdk_updates.py "$@"
}

client() {
  local caller=$1 target=$2 request_id=$3 update_name=${4:-echo}
  local arguments=("${COMPOSE_PROJECT_NAME}-${target}" "$request_id" "$update_name")
  if [[ $# == 5 ]]; then arguments+=("$5"); fi
  case "$caller" in
    php) "${compose[@]}" exec -T --user 1000:1000 php-same-workflow-worker \
      php -d display_errors=stderr /app/update_client.php "${arguments[@]}" ;;
    python) observer call "${arguments[@]}" ;;
    rust) "${compose[@]}" exec -T --user 1000:1000 -e POLYGLOT_RUST_MODE=update-client \
      rust-workflow-worker polyglot-rust-worker "${arguments[@]}" ;;
  esac
}

DURABLE_WORKFLOW_UPDATE_RUNS="$(observer start)"
export DURABLE_WORKFLOW_UPDATE_RUNS
printf '%s\n' "$DURABLE_WORKFLOW_UPDATE_RUNS"
export DURABLE_WORKFLOW_UPDATE_RESULTS=''
for direction in php:php php:python php:rust python:php python:python python:rust rust:php rust:python rust:rust; do
  caller=${direction%:*}
  target=${direction#*:}
  if ! result="$(client "$caller" "$target" "${COMPOSE_PROJECT_NAME}-${caller}-${target}")"; then
    printf 'SDK client %s failed:\n%s\n' "$direction" "$result" >&2
    exit 1
  fi
  DURABLE_WORKFLOW_UPDATE_RESULTS+="${result}"$'\n'
done
observer matrix
observer snapshot

export DURABLE_WORKFLOW_STATE_RESULTS=''
for caller in php python rust; do
  case "$caller" in php) delta=1 ;; python) delta=2 ;; rust) delta=3 ;; esac
  for target in php python rust; do
    result="$(client "$caller" "$target" "${COMPOSE_PROJECT_NAME}-increment-${caller}-${target}" increment "$delta")"
    DURABLE_WORKFLOW_STATE_RESULTS+="${result}"$'\n'
  done
done
observer state_matrix

"${compose[@]}" kill --signal SIGKILL "${workers[@]}"
DURABLE_WORKFLOW_UPDATE_QUEUED="$(observer queued)"
export DURABLE_WORKFLOW_UPDATE_QUEUED
printf '%s\n' "$DURABLE_WORKFLOW_UPDATE_QUEUED"
DURABLE_WORKFLOW_STATE_QUEUED="$(observer state_queued)"
export DURABLE_WORKFLOW_STATE_QUEUED
printf '%s\n' "$DURABLE_WORKFLOW_STATE_QUEUED"
"${compose[@]}" up -d --wait --no-build "${workers[@]}"
observer replacement
observer state_replacement
observer failure
export DURABLE_WORKFLOW_STATE_DUPLICATES=''
for caller in php python rust; do
  case "$caller" in php) delta=1 ;; python) delta=2 ;; rust) delta=3 ;; esac
  for target in php python rust; do
    result="$(client "$caller" "$target" "${COMPOSE_PROJECT_NAME}-increment-${caller}-${target}" increment "$delta")"
    DURABLE_WORKFLOW_STATE_DUPLICATES+="${result}"$'\n'
  done
done
observer state_duplicates
"${compose[@]}" exec -T --user 1000:1000 -e POLYGLOT_RUST_MODE=validator-refusal \
  rust-workflow-worker polyglot-rust-worker
observer finish
observer finish_race
printf 'SDK updates pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
