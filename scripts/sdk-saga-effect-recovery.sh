#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 0 ]]; then
  printf 'Usage: scripts/sdk-saga-effect-recovery.sh\n' >&2
  exit 2
fi

cd "$(dirname "${BASH_SOURCE[0]}")/.."
assignments=$(scripts/resolve-current-artifacts.sh)
while IFS= read -r assignment; do export "${assignment:?}"; done <<< "$assignments"
scripts/prepare-apt-build-source.sh >&2

export COMPOSE_PROJECT_NAME="${SDK_SAGA_COMPOSE_PROJECT_NAME:-sample-app-saga-effects-${BASHPID}}"
export COMPOSE_PARALLEL_LIMIT=1
if [[ ! "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  printf 'Use a valid Compose project name.\n' >&2
  exit 2
fi
export SAGA_FIXTURE_TOKEN="local-$COMPOSE_PROJECT_NAME"
ACTIVITY_RECOVERY_UID=$(id -u)
ACTIVITY_RECOVERY_GID=$(id -g)
export ACTIVITY_RECOVERY_UID ACTIVITY_RECOVERY_GID
compose=(docker compose -f polyglot/docker-compose.yml -f polyglot/docker-compose.activity-recovery.yml
  -f polyglot/docker-compose.sagas.yml --profile external-effects)
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
export ACTIVITY_RECOVERY_PROOF_DIR
ACTIVITY_RECOVERY_PROOF_DIR=$(mktemp -d "${TMPDIR:-/tmp}/sample-app-saga-effects.XXXXXXXX")
cleanup() {
  local result=$? remaining php_image="$COMPOSE_PROJECT_NAME-php-sdk-worker:latest"
  if (( result != 0 )); then
    "${compose[@]}" logs --tail 15 --no-color >&2 || true
  fi
  "${compose[@]}" down --volumes --remove-orphans --rmi local >&2 || result=1
  if docker image inspect "$php_image" >/dev/null 2>&1; then
    docker image rm --no-prune "$php_image" >&2 || result=1
  fi
  if ! remaining=$(project_resources) || [[ -n "$remaining" ]]; then
    printf 'Could not verify removal of all resources for %s.\n' "$COMPOSE_PROJECT_NAME" >&2
    result=1
  fi
  find "$ACTIVITY_RECOVERY_PROOF_DIR" -xdev -depth -delete || result=1
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

declare -A workers=([php]=php-same-workflow-worker [python]=saga-python [rust]=rust-workflow-worker)
"${compose[@]}" build smoke "${workers[php]}" "${workers[rust]}" >&2
"${compose[@]}" up -d --wait --wait-timeout 150 --no-build server recovery-effects "${workers[@]}" >&2
printf 'Sample App %s with Server %s and SDKs PHP %s, Python %s, Rust %s\n' \
  "$(git rev-parse HEAD)" "$DURABLE_SERVER_IMAGE" "$DURABLE_WORKFLOW_PHP_SDK_VERSION" \
  "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION" "$DURABLE_WORKFLOW_RUST_SDK_VERSION" >&2

observe() {
  "${compose[@]}" run -T --rm --no-deps smoke python /app/sagas/effect_recovery_client.py "$@"
}

for workflow in php python rust; do
  for compensation in php python rust; do
    worker="${workers[$compensation]}"
    before=$(observe start "$workflow" "$compensation")
    original=$("${compose[@]}" ps -q "$worker")
    [[ -n "$original" ]]
    "${compose[@]}" kill --signal SIGKILL "$worker" >&2
    [[ $(docker inspect --format '{{.State.ExitCode}} {{.State.OOMKilled}} {{.State.Running}}' "$original") == '137 false false' ]]
    "${compose[@]}" up -d --wait --wait-timeout 30 --no-build --no-deps --force-recreate "$worker" >&2
    replacement=$("${compose[@]}" ps -q "$worker")
    [[ -n "$replacement" && "$original" != "$replacement" ]]
    observe verify "$before" "$original" "$replacement"
  done
done
