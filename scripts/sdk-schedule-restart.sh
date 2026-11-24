#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
assignments=$(scripts/resolve-current-artifacts.sh)
while IFS= read -r assignment; do export "${assignment:?}"; done <<< "$assignments"
scripts/prepare-apt-build-source.sh >&2

export COMPOSE_PROJECT_NAME="${SDK_SCHEDULE_RESTART_COMPOSE_PROJECT_NAME:-sample-app-schedule-restart-${BASHPID}}"
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
scratch=$(mktemp -d "${TMPDIR:-/tmp}/sample-app-schedule-restart.XXXXXXXX")
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
"${compose[@]}" up -d --wait --wait-timeout 150 --no-build server "${workers[@]}" >&2
server_id=$("${compose[@]}" ps -q server)
database_id=$("${compose[@]}" ps -q mysql)
original_start=$(docker inspect --format '{{.State.StartedAt}}' "$server_id")
database_start=$(docker inspect --format '{{.State.StartedAt}}' "$database_id")
php_version=$("${compose[@]}" exec -T php-same-workflow-worker php -r '
  require "/app/vendor/autoload.php";
  echo \Composer\InstalledVersions::getPrettyVersion("durable-workflow/sdk");
')
python_version=$("${compose[@]}" exec -T python-workflow-worker python -c '
from importlib.metadata import version
print(version("durable-workflow"))
')
test "$php_version" = "$DURABLE_WORKFLOW_PHP_SDK_VERSION"
test "$python_version" = "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION"
printf 'Sample App %s with Server %s and SDKs PHP %s, Python %s, Rust %s\n' \
  "$(git rev-parse HEAD)" "$DURABLE_SERVER_IMAGE" "$DURABLE_WORKFLOW_PHP_SDK_VERSION" \
  "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION" "$DURABLE_WORKFLOW_RUST_SDK_VERSION" >&2

jobs=()
for runtime in php python rust; do
  "${compose[@]}" run -T --rm --no-deps \
    -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
    -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
    -e DURABLE_WORKFLOW_TASK_QUEUE=polyglot-rust \
    smoke python /app/scripts/python_created_rust_schedule.py "$runtime" --keep-schedule \
    > "$scratch/python-$runtime.log" &
  jobs+=("$!")
  "${compose[@]}" run -T --rm --no-deps \
    -e POLYGLOT_PY_TASK_QUEUE=polyglot-python \
    -e POLYGLOT_RUST_TASK_QUEUE=polyglot-rust \
    php-same-workflow-worker php /app/php_created_rust_schedule.php "$runtime" --keep-schedule \
    > "$scratch/php-$runtime.log" &
  jobs+=("$!")
done
result=0
for job in "${jobs[@]}"; do wait "$job" || result=1; done
if (( result != 0 )); then
  cat "$scratch"/*.log >&2
  exit "$result"
fi
sed -n '/^{/p' "$scratch"/*.log > "$scratch/originals.jsonl"

"${compose[@]}" stop -t 5 "${workers[@]}" >&2
"${compose[@]}" stop -t 5 server >&2
test "$(docker inspect --format '{{.State.Running}}' "$server_id")" = false
stopped_at=$(docker inspect --format '{{.State.FinishedAt}}' "$server_id")
sleep 12
"${compose[@]}" up -d --wait --wait-timeout 90 --no-build --no-deps server >&2
test "$("${compose[@]}" ps -q server)" = "$server_id"
test "$("${compose[@]}" ps -q mysql)" = "$database_id"
test "$(docker inspect --format '{{.State.StartedAt}}' "$database_id")" = "$database_start"
restarted_at=$(docker inspect --format '{{.State.StartedAt}}' "$server_id")
test "$restarted_at" != "$original_start"
printf 'Server stopped at %s and restarted at %s.\n' "$stopped_at" "$restarted_at" >&2
"${compose[@]}" up -d --wait --wait-timeout 90 --no-build --no-deps "${workers[@]}" >&2
"${compose[@]}" run -T --rm --no-deps smoke python /app/scripts/verify_schedule_restart.py \
  "$stopped_at" "$restarted_at" < "$scratch/originals.jsonl"
