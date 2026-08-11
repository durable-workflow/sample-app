#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root/polyglot"

: "${COMPOSE_PROJECT_NAME:?Set an isolated COMPOSE_PROJECT_NAME before running polyglot validation}"

build_services=(
  python-activity-worker
  php-same-workflow-worker
  php-same-activity-worker
  php-workflow-worker
  polyglot-workflow-worker
  php-to-rust-workflow-worker
  php-query-worker
  php-activity-worker
  python-workflow-worker
  rust-workflow-worker
  rust-activity-worker
  waterline
  smoke
)
topology_services=(
  server
  python-activity-worker
  php-same-workflow-worker
  php-same-activity-worker
  php-workflow-worker
  polyglot-workflow-worker
  php-to-rust-workflow-worker
  php-query-worker
  php-activity-worker
  python-workflow-worker
  rust-workflow-worker
  rust-activity-worker
  waterline
)

server_container_id=""
failure_context="validation failure"

compose_diagnostics() {
  local context="$1"
  local lines="${POLYGLOT_DIAGNOSTIC_LOG_LINES:-240}"

  printf '\npolyglot-validation: diagnostics after %s\n' "$context" >&2
  printf 'polyglot-validation: expected server container=%s current=%s\n' \
    "${server_container_id:-not-captured}" \
    "$(docker compose ps -q server 2>/dev/null || true)" >&2
  docker compose ps --all >&2 || true
  docker compose images >&2 || true
  docker compose logs \
    --no-color \
    --timestamps \
    --tail="$lines" \
    bootstrap \
    server \
    python-activity-worker \
    php-same-workflow-worker \
    php-same-activity-worker \
    php-workflow-worker \
    polyglot-workflow-worker \
    php-to-rust-workflow-worker \
    php-query-worker \
    php-activity-worker \
    python-workflow-worker \
    rust-workflow-worker \
    rust-activity-worker \
    waterline >&2 || true
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM

  if [[ "$status" -ne 0 ]]; then
    compose_diagnostics "$failure_context"
  fi

  printf '\n==> removing isolated polyglot Compose project %s\n' "$COMPOSE_PROJECT_NAME"
  if ! timeout "${POLYGLOT_CLEANUP_TIMEOUT_SECONDS:-120}s" \
    docker compose down \
      --volumes \
      --remove-orphans \
      --rmi local \
      --timeout "${POLYGLOT_COMPOSE_STOP_TIMEOUT_SECONDS:-30}"
  then
    printf 'polyglot-validation: Compose project cleanup failed or timed out\n' >&2
    if [[ "$status" -eq 0 ]]; then
      status=1
    fi
  fi

  exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

run_step() {
  local name="$1"
  local timeout_seconds="$2"
  shift 2

  printf '\n==> %s\n' "$name"

  set +e
  timeout "${timeout_seconds}s" "$@"
  local status=$?
  set -e

  if [[ "$status" -eq 0 ]]; then
    return 0
  fi

  if [[ "$status" -eq 124 ]]; then
    printf 'polyglot-validation: %s timed out after %ss\n' "$name" "$timeout_seconds" >&2
  else
    printf 'polyglot-validation: %s exited with status %d\n' "$name" "$status" >&2
  fi
  failure_context="$name"

  return "$status"
}

assert_server_stable() {
  local context="$1"
  local current_container_id
  local running
  local health

  current_container_id="$(docker compose ps -q server)"
  running="$(docker inspect --format '{{.State.Running}}' "$current_container_id" 2>/dev/null || true)"
  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$current_container_id" 2>/dev/null || true)"

  if [[ -z "$server_container_id" ]]; then
    server_container_id="$current_container_id"
  fi

  if [[ -z "$current_container_id" || "$current_container_id" != "$server_container_id" ]]; then
    failure_context="$context"
    printf 'polyglot-validation: server container changed during %s (expected=%s actual=%s)\n' \
      "$context" \
      "${server_container_id:-missing}" \
      "${current_container_id:-missing}" >&2
    return 1
  fi

  if [[ "$running" != "true" || "$health" != "healthy" ]]; then
    failure_context="$context"
    printf 'polyglot-validation: server is not healthy during %s (running=%s health=%s)\n' \
      "$context" \
      "${running:-unknown}" \
      "${health:-unknown}" >&2
    return 1
  fi

  printf 'polyglot-validation: stable server container %s is healthy after %s\n' \
    "$server_container_id" \
    "$context"
}

cache_mode="${POLYGLOT_BUILD_CACHE_MODE:-cold-cache}"
build_timeout_seconds="${POLYGLOT_BUILD_TIMEOUT_SECONDS:-720}"

case "$cache_mode" in
  cold-cache)
    run_step \
      "building the complete exact-tuple topology from a cold cache" \
      "$build_timeout_seconds" \
      docker compose build --pull --no-cache "${build_services[@]}"
    ;;
  warm-cache)
    run_step \
      "priming the exact-tuple build cache" \
      "$build_timeout_seconds" \
      docker compose build --pull "${build_services[@]}"
    run_step \
      "rebuilding the complete exact-tuple topology from the warm cache" \
      "${POLYGLOT_WARM_BUILD_TIMEOUT_SECONDS:-240}" \
      docker compose build "${build_services[@]}"
    ;;
  *)
    printf 'polyglot-validation: unsupported POLYGLOT_BUILD_CACHE_MODE=%s (expected cold-cache or warm-cache)\n' \
      "$cache_mode" >&2
    exit 2
    ;;
esac

run_step \
  "pulling exact runtime and datastore images" \
  "${POLYGLOT_IMAGE_PULL_TIMEOUT_SECONDS:-180}" \
  docker compose pull --policy missing bootstrap server mysql redis

run_step \
  "starting the complete polyglot topology with one server bootstrap" \
  "${POLYGLOT_TOPOLOGY_TIMEOUT_SECONDS:-240}" \
  docker compose up \
    --detach \
    --no-build \
    --wait \
    --wait-timeout "${POLYGLOT_COMPOSE_WAIT_SECONDS:-180}" \
    "${topology_services[@]}"

assert_server_stable "topology readiness"

run_step \
  "proving every required worker registration on the stable server" \
  "${POLYGLOT_REGISTRATION_STEP_TIMEOUT_SECONDS:-150}" \
  docker compose run \
    --rm \
    --no-deps \
    -e "POLYGLOT_REGISTRATION_TIMEOUT_SECONDS=${POLYGLOT_REGISTRATION_TIMEOUT_SECONDS:-90}" \
    smoke \
    python \
    /app/scripts/polyglot_smoke.py \
    --readiness-only

assert_server_stable "worker registration"

run_step \
  "running polyglot smoke against the stable server" \
  "${POLYGLOT_SMOKE_TIMEOUT_SECONDS:-600}" \
  docker compose run --rm --no-deps smoke

assert_server_stable "polyglot smoke"
printf '\npolyglot-validation: %s validation passed on server container %s\n' \
  "$cache_mode" \
  "$server_container_id"
