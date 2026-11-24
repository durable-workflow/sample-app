#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 1 || ! "${1:-}" =~ ^(restart|backfill)$ ]]; then
  printf 'Run scripts/sdk-schedules.sh with restart or backfill.\n' >&2
  exit 2
fi
scenario=$1

cd "$(dirname "${BASH_SOURCE[0]}")/.."
assignments=$(scripts/resolve-current-artifacts.sh)
while IFS= read -r assignment; do export "${assignment:?}"; done <<< "$assignments"
scripts/prepare-apt-build-source.sh >&2

export COMPOSE_PROJECT_NAME="${SDK_SCHEDULE_RESTART_COMPOSE_PROJECT_NAME:-sample-app-schedules-${BASHPID}}"
export COMPOSE_PARALLEL_LIMIT=1
compose=(docker compose -f polyglot/docker-compose.yml -f polyglot/docker-compose.schedules.yml)
workers=(php-same-workflow-worker php-same-activity-worker python-workflow-worker rust-workflow-worker)
if [[ ! "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  printf 'Use a valid Compose project name.\n' >&2
  exit 2
fi

project_label="label=com.docker.compose.project=$COMPOSE_PROJECT_NAME"
project_resources() {
  docker container ls -aq --filter "$project_label" &&
    docker network ls -q --filter "$project_label" &&
    docker volume ls -q --filter "$project_label"
}

existing=$(project_resources)
images=$(docker image ls -q --filter "reference=$COMPOSE_PROJECT_NAME-*")
if [[ -n "$existing" || -n "$images" ]]; then
  printf 'Use a new isolated Compose project. %s already has resources.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi
scratch=$(mktemp -d "${TMPDIR:-/tmp}/sample-app-schedules.XXXXXXXX")
cleanup() {
  local result=$?
  if (( result != 0 )); then
    "${compose[@]}" logs --tail 15 --no-color >&2 || true
  fi
  "${compose[@]}" down --volumes --remove-orphans --rmi local >&2 || result=1
  local php_image="$COMPOSE_PROJECT_NAME-php-sdk-worker:latest"
  if docker image inspect "$php_image" >/dev/null 2>&1; then
    docker image rm --no-prune "$php_image" >&2 || result=1
  fi
  local remaining
  if ! remaining=$(project_resources) || [[ -n "$remaining" ]]; then
    printf 'Could not verify removal of all resources for %s.\n' "$COMPOSE_PROJECT_NAME" >&2
    result=1
  fi
  find "$scratch" -xdev -depth -delete
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

"${compose[@]}" build php-same-workflow-worker python-workflow-worker rust-workflow-worker smoke >&2
"${compose[@]}" up -d --wait --wait-timeout 150 --no-build server >&2
printf 'Sample App %s with Server %s and SDKs PHP %s, Python %s, Rust %s\n' \
  "$(git rev-parse HEAD)" "$DURABLE_SERVER_IMAGE" "$DURABLE_WORKFLOW_PHP_SDK_VERSION" \
  "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION" "$DURABLE_WORKFLOW_RUST_SDK_VERSION" >&2

start_workers() {
  local php_version python_version
  "${compose[@]}" up -d --wait --wait-timeout 90 --no-build --no-deps "${workers[@]}" >&2
  php_version=$("${compose[@]}" exec -T php-same-workflow-worker php -r '
    require "/app/vendor/autoload.php";
    echo \Composer\InstalledVersions::getPrettyVersion("durable-workflow/sdk");
  ')
  python_version=$("${compose[@]}" exec -T python-workflow-worker \
    python -c 'from importlib.metadata import version; print(version("durable-workflow"))')
  [[ "$php_version" == "$DURABLE_WORKFLOW_PHP_SDK_VERSION" ]]
  [[ "$python_version" == "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION" ]]
}

run_creators() {
  local phase=$1 hour=${2:-} runtime job result=0
  local -a jobs=() args
  local php_script python_script
  if [[ "$phase" == restart ]]; then
    php_script=php_created_rust_schedule.php
    python_script=python_created_rust_schedule.py
  else
    php_script=schedule_backfill.php
    python_script=schedule_backfill.py
  fi
  for runtime in php python rust; do
    if [[ "$phase" == restart ]]; then
      args=("$runtime" --keep-schedule)
    else
      args=("$phase" "$runtime" "$hour")
    fi
    "${compose[@]}" run -T --rm --no-deps \
      -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
      -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
      -e DURABLE_WORKFLOW_TASK_QUEUE=polyglot-rust \
      smoke python "/app/scripts/$python_script" "${args[@]}" \
      > "$scratch/$phase-python-$runtime.log" &
    jobs+=("$!")
    "${compose[@]}" run -T --rm --no-deps \
      -e POLYGLOT_PY_TASK_QUEUE=polyglot-python \
      -e POLYGLOT_RUST_TASK_QUEUE=polyglot-rust \
      php-same-workflow-worker php "/app/$php_script" "${args[@]}" \
      > "$scratch/$phase-php-$runtime.log" &
    jobs+=("$!")
  done
  for job in "${jobs[@]}"; do wait "$job" || result=1; done
  if (( result != 0 )); then
    cat "$scratch/$phase"-*.log >&2
    return "$result"
  fi
  sed -n '/^{/p' "$scratch/$phase"-*.log
}

if [[ "$scenario" == backfill ]]; then
  hour=$(printf '%02d' "$(( (10#$(date -u +%H) + 12) % 24 ))")
  run_creators initial "$hour" > "$scratch/initial.jsonl"
  start_workers
  "${compose[@]}" run -T --rm --no-deps smoke python /app/scripts/verify_schedule_backfill.py \
    initial "$hour" < "$scratch/initial.jsonl" > "$scratch/originals.jsonl"
  previous="$scratch/originals.jsonl"
  for phase in repeat timeout recover; do
    run_creators "$phase" "$hour" > "$scratch/$phase.jsonl"
    cat "$previous" "$scratch/$phase.jsonl" |
      "${compose[@]}" run -T --rm --no-deps smoke python /app/scripts/verify_schedule_backfill.py "$phase" "$hour" \
        > "$scratch/verified-$phase.jsonl"
    previous="$scratch/verified-$phase.jsonl"
  done
  cat "$previous"
  exit
fi

server_id=$("${compose[@]}" ps -q server)
database_id=$("${compose[@]}" ps -q mysql)
original_start=$(docker inspect --format '{{.State.StartedAt}}' "$server_id")
database_start=$(docker inspect --format '{{.State.StartedAt}}' "$database_id")
start_workers
run_creators restart > "$scratch/originals.jsonl"

"${compose[@]}" stop -t 5 "${workers[@]}" >&2
"${compose[@]}" stop -t 5 server >&2
[[ "$(docker inspect --format '{{.State.Running}}' "$server_id")" == false ]]
stopped_at=$(docker inspect --format '{{.State.FinishedAt}}' "$server_id")
sleep 12
"${compose[@]}" up -d --wait --wait-timeout 90 --no-build --no-deps server >&2
[[ "$("${compose[@]}" ps -q server)" == "$server_id" ]]
[[ "$("${compose[@]}" ps -q mysql)" == "$database_id" ]]
[[ "$(docker inspect --format '{{.State.StartedAt}}' "$database_id")" == "$database_start" ]]
restarted_at=$(docker inspect --format '{{.State.StartedAt}}' "$server_id")
[[ "$restarted_at" != "$original_start" ]]
printf 'Server stopped at %s and restarted at %s.\n' "$stopped_at" "$restarted_at" >&2
start_workers
"${compose[@]}" run -T --rm --no-deps smoke python /app/scripts/verify_schedule_restart.py \
  "$stopped_at" "$restarted_at" < "$scratch/originals.jsonl"
