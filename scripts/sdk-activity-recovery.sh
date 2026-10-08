#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-activity-recovery.sh [--result-dir DIR]' \
    'Runs retry and activity-worker SIGKILL in all five Rust-involving SDK directions.' \
    'Requires Docker Compose and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_ACTIVITY_RECOVERY_COMPOSE_PROJECT_NAME selects an isolated project. All task resources are removed.'
  exit 0
fi
result_dir=""
if [[ "${1:-}" == --result-dir && $# == 2 ]]; then result_dir="$2"; elif [[ $# != 0 ]]; then exit 2; fi
if [[ -z "$result_dir" ]]; then result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-sdk-activity-recovery.XXXXXX")"; fi
mkdir -p "$result_dir/proof" "$result_dir/logs"
result_dir="$(cd "$result_dir" && pwd)"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_ACTIVITY_RECOVERY_COMPOSE_PROJECT_NAME:-sample-app-activity-recovery-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export ACTIVITY_RECOVERY_PROOF_DIR="$result_dir/proof"
compose=(docker compose --project-directory "$repo_root/polyglot" -f "$repo_root/polyglot/docker-compose.yml" \
  -f "$repo_root/polyglot/docker-compose.activity-recovery.yml")
workers=(recovery-workflow-php recovery-workflow-python recovery-workflow-rust \
  recovery-activity-php recovery-activity-python recovery-activity-rust)
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi
cleanup() {
  local code=$? service image
  for service in server recovery-timeouts "${workers[@]}"; do
    "${compose[@]}" logs --no-color --timestamps "$service" > "$result_dir/logs/$service.log" 2>&1 || true
  done
  "${compose[@]}" down --volumes --remove-orphans || return 1
  for service in php-sdk-worker rust-workflow-worker smoke; do
    image="${COMPOSE_PROJECT_NAME}-${service}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then docker image rm --no-prune "$image" || return 1; fi
  done
  rm -f "$result_dir"/proof/*.release "$result_dir"/proof/*.first-release "$result_dir"/proof/*.pending
  printf 'SDK activity cleanup complete: %s\n' "$result_dir"
  return "$code"
}
trap cleanup EXIT
printf 'SDK activity recovery start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for name in DURABLE_SERVER_IMAGE DURABLE_SERVER_VERSION DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke php-same-workflow-worker rust-workflow-worker
"${compose[@]}" run --rm --no-deps --user 1000:1000 -T smoke \
  python -m unittest discover -s /app/scripts -p test_sdk_activity_recovery.py
"${compose[@]}" pull --policy always bootstrap server recovery-timeouts
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server recovery-timeouts "${workers[@]}"
docker image inspect "$DURABLE_SERVER_IMAGE" > "$result_dir/server-image.json"
[[ "$(docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{index .Config.Labels "org.opencontainers.image.version"}}')" == "$DURABLE_SERVER_VERSION" ]]
[[ "$(docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{index .Config.Labels "dev.durable-workflow.workflow.version"}}')" == "$DURABLE_WORKFLOW_WORKFLOW_VERSION" ]]

observer() {
  "${compose[@]}" run --rm --no-deps --user 1000:1000 -T \
    -e ACTIVITY_RECOVERY_CASE -e ACTIVITY_RECOVERY_WORKFLOW -e ACTIVITY_RECOVERY_ACTIVITY \
    -e ACTIVITY_RECOVERY_SCENARIO -e ACTIVITY_RECOVERY_RUNNER_COMMIT \
    smoke python /app/scripts/sdk_activity_recovery.py "$@"
}
snapshot() {
  docker inspect "$1" | jq '.[0] | {Id,State:{Pid:.State.Pid,StartedAt:.State.StartedAt,FinishedAt:.State.FinishedAt,ExitCode:.State.ExitCode,OOMKilled:.State.OOMKilled,Running:.State.Running}}'
}
for direction in php:rust python:rust rust:php rust:python rust:rust; do
  export ACTIVITY_RECOVERY_WORKFLOW="${direction%%:*}" ACTIVITY_RECOVERY_ACTIVITY="${direction##*:}"
  for scenario in retry worker-loss; do
    export ACTIVITY_RECOVERY_SCENARIO="$scenario"
    export ACTIVITY_RECOVERY_CASE="$COMPOSE_PROJECT_NAME-$scenario-${direction/:/-}"
    observer start
    observer first
    if [[ "$scenario" == retry ]]; then
      observer release-first
    else
      activity_service="recovery-activity-$ACTIVITY_RECOVERY_ACTIVITY"
      old_container="$("${compose[@]}" ps -q "$activity_service")"
      snapshot "$old_container" > "$result_dir/proof/$ACTIVITY_RECOVERY_CASE.container-before.json"
      "${compose[@]}" kill --signal SIGKILL "$activity_service"
      snapshot "$old_container" > "$result_dir/proof/$ACTIVITY_RECOVERY_CASE.container-killed.json"
      jq -e '.State.ExitCode == 137 and .State.OOMKilled == false and .State.Running == false' \
        "$result_dir/proof/$ACTIVITY_RECOVERY_CASE.container-killed.json"
      "${compose[@]}" up -d --wait --no-build --force-recreate "$activity_service"
      new_container="$("${compose[@]}" ps -q "$activity_service")"
      [[ "$old_container" != "$new_container" ]]
      snapshot "$new_container" > "$result_dir/proof/$ACTIVITY_RECOVERY_CASE.container-replacement.json"
    fi
    observer second
    ACTIVITY_RECOVERY_CLAIM="$(jq -c .claim "$result_dir/proof/$ACTIVITY_RECOVERY_CASE.first.json")"
    export ACTIVITY_RECOVERY_CLAIM
    "${compose[@]}" run --rm --no-deps --user 1000:1000 -T \
      -e ACTIVITY_RECOVERY_MODE=stale -e ACTIVITY_RECOVERY_CLAIM recovery-workflow-rust \
      > "$result_dir/proof/$ACTIVITY_RECOVERY_CASE.stale.json"
    observer stale
    observer release
    observer verify
  done
done
observer summary
printf 'SDK activity recovery pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
