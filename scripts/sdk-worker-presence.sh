#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 0 ]]; then
  printf 'Usage: scripts/sdk-worker-presence.sh\n' >&2
  exit 2
fi

cd "$(dirname "${BASH_SOURCE[0]}")/.."
assignments=$(scripts/resolve-current-artifacts.sh)
while IFS= read -r assignment; do export "${assignment:?}"; done <<< "$assignments"
scripts/prepare-apt-build-source.sh >&2

export COMPOSE_PROJECT_NAME="${SDK_WORKER_PRESENCE_COMPOSE_PROJECT_NAME:-sample-app-worker-presence-${BASHPID}}"
export COMPOSE_PARALLEL_LIMIT=1
if [[ ! "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  printf 'Use a valid Compose project name.\n' >&2
  exit 2
fi
export SDK_PRESENCE_PHP_ID=presence-php-initial
export SDK_PRESENCE_PYTHON_ID=presence-python-initial
export SDK_PRESENCE_RUST_ID=presence-rust-initial
compose=(docker compose -f polyglot/docker-compose.yml -f polyglot/docker-compose.schedules.yml
  -f polyglot/docker-compose.worker-presence.yml)
scope="label=com.docker.compose.project=$COMPOSE_PROJECT_NAME"
project_resources() {
  docker container ls -aq --filter "$scope" &&
    docker network ls -q --filter "$scope" &&
    docker volume ls -q --filter "$scope"
}
existing=$(project_resources)
images=$(docker image ls -q --filter "reference=$COMPOSE_PROJECT_NAME-*")
if [[ -n "$existing" || -n "$images" ]]; then
  printf 'Use a new isolated Compose project. %s already has resources.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi
cleanup() {
  local result=$? remaining php_image="$COMPOSE_PROJECT_NAME-php-sdk-worker:latest"
  if (( result != 0 )); then
    "${compose[@]}" logs --tail 12 --no-color >&2 || true
  fi
  "${compose[@]}" down --volumes --remove-orphans --rmi local >&2 || result=1
  if docker image inspect "$php_image" >/dev/null 2>&1; then
    docker image rm --no-prune "$php_image" >&2 || result=1
  fi
  if ! remaining=$(project_resources) || [[ -n "$remaining" ]]; then
    printf 'Could not verify removal of all resources for %s.\n' "$COMPOSE_PROJECT_NAME" >&2
    result=1
  fi
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

declare -A workers=([php]=php-same-workflow-worker [python]=python-workflow-worker [rust]=rust-workflow-worker)
"${compose[@]}" build smoke "${workers[@]}" >&2
"${compose[@]}" up -d --wait --wait-timeout 150 --no-build server php-same-activity-worker "${workers[@]}" >&2
printf 'Sample App %s with Server %s and SDKs PHP %s, Python %s, Rust %s\n' \
  "$(git rev-parse HEAD)" "$DURABLE_SERVER_IMAGE" "$DURABLE_WORKFLOW_PHP_SDK_VERSION" \
  "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION" "$DURABLE_WORKFLOW_RUST_SDK_VERSION" >&2

observe() {
  "${compose[@]}" run -T --rm --no-deps smoke python /app/scripts/verify_worker_presence.py "$@"
}

for runtime in php python rust; do
  service="${workers[$runtime]}"
  id_var="SDK_PRESENCE_${runtime^^}_ID"
  version_var="DURABLE_WORKFLOW_${runtime^^}_SDK_VERSION"
  initial_id="${!id_var}"
  active=$(observe active "$runtime" "$initial_id" "${!version_var}")
  original=$("${compose[@]}" ps -q "$service")
  "${compose[@]}" kill --signal SIGINT "$service" >&2
  exit_code=$(timeout 20 docker wait "$original")
  [[ "$exit_code" == 0 || "$exit_code" == 130 ]]
  observe deregistered "$runtime" "$initial_id"

  abrupt_id="presence-$runtime-abrupt"
  export "$id_var=$abrupt_id"
  "${compose[@]}" up -d --wait --wait-timeout 30 --no-build --no-deps --force-recreate "$service" >&2
  observe active "$runtime" "$abrupt_id" "${!version_var}" >/dev/null
  abrupt=$("${compose[@]}" ps -q "$service")
  [[ -n "$abrupt" && "$abrupt" != "$original" ]]
  "${compose[@]}" kill --signal SIGKILL "$service" >&2
  [[ $(docker inspect --format '{{.State.ExitCode}} {{.State.OOMKilled}} {{.State.Running}}' "$abrupt") == '137 false false' ]]
  stale=$(observe stale "$runtime" "$abrupt_id")

  fresh_id="presence-$runtime-replacement"
  export "$id_var=$fresh_id"
  "${compose[@]}" up -d --wait --wait-timeout 30 --no-build --no-deps --force-recreate "$service" >&2
  fresh=$("${compose[@]}" ps -q "$service")
  [[ -n "$fresh" && "$fresh" != "$abrupt" ]]
  result=$(observe workflow "$runtime" "$fresh_id" "${!version_var}")
  jq -cn --argjson active "$active" --argjson stale "$stale" --argjson replacement "$result" \
    '{active:$active,gracefully_deregistered:true,stale:$stale,replacement:$replacement}'
done
