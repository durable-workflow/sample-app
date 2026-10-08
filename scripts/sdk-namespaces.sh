#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-namespaces.sh' \
    'Runs published Rust clients/workers in two namespaces with identical queue and workflow names.' \
    'Checks role credentials, denied reads/mutations/polls and original-run recovery after SIGKILL.' \
    'Requires Docker Compose and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_NAMESPACES_COMPOSE_PROJECT_NAME selects an isolated project. Project resources are removed on exit.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_NAMESPACES_COMPOSE_PROJECT_NAME:-sample-app-sdk-namespaces-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
compose=(docker compose --project-directory "$repo_root/polyglot" \
  -f "$repo_root/polyglot/docker-compose.yml" -f "$repo_root/polyglot/docker-compose.namespaces.yml")
workers=(rust-namespace-a rust-namespace-b)
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi
cleanup() {
  local code=$?
  if [[ "$code" != 0 ]]; then "${compose[@]}" logs --no-color --timestamps || true; fi
  "${compose[@]}" down --volumes --remove-orphans || return 1
  local suffix image
  for suffix in rust-workflow-worker smoke; do
    image="${COMPOSE_PROJECT_NAME}-${suffix}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then docker image rm --no-prune "$image" || return 1; fi
  done
  return "$code"
}
trap cleanup EXIT
printf 'SDK namespaces start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke rust-workflow-worker
"${compose[@]}" pull --policy always bootstrap server timer-queue
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'
observer() {
  "${compose[@]}" run --rm --no-deps --user 1000:1000 -e DURABLE_WORKFLOW_NAMESPACE_RUNS \
    smoke python /app/scripts/sdk_namespaces.py "$@"
}
caller() {
  local namespace="$1" credential_namespace="$2" role="$3" phase="$4" id="$5"
  local control="" worker=""
  if [[ "$role" == operator || "$role" == both ]]; then
    control="dwr_fixture_operator_${credential_namespace//-/_}_0123456789"
  fi
  if [[ "$role" == worker || "$role" == both ]]; then
    worker="dwr_fixture_worker_${credential_namespace//-/_}_0123456789"
  fi
  "${compose[@]}" run --rm --no-deps --user 1000:1000 \
    -e DURABLE_WORKFLOW_AUTH_TOKEN= \
    -e "DURABLE_WORKFLOW_CONTROL_TOKEN=$control" -e "DURABLE_WORKFLOW_WORKER_TOKEN=$worker" \
    -e "DURABLE_WORKFLOW_NAMESPACE=$namespace" -e POLYGLOT_RUST_MODE=namespace-client \
    -e "DURABLE_WORKFLOW_NAMESPACE_PHASE=$phase" -e "DURABLE_WORKFLOW_NAMESPACE_ID=$id" \
    rust-workflow-worker
}
observer setup
"${compose[@]}" up -d --wait --no-build "${workers[@]}"
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator start same-workflow-id
  caller "$namespace" "$namespace" operator start "only-$namespace"
done
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" both routed same-workflow-id
  caller "$namespace" "$namespace" worker deny-describe same-workflow-id
  # The operator role can perform diagnostic registration. Polling requires worker authority.
  caller "$namespace" "$namespace" operator deny-poll same-workflow-id
done
# Namespace A credentials cannot access B, default, or an unregistered namespace.
for namespace in rust-namespace-b default missing-namespace; do
  caller "$namespace" rust-namespace-a both deny-describe same-workflow-id
  caller "$namespace" rust-namespace-a both deny-signal same-workflow-id
  caller "$namespace" rust-namespace-a both deny-start denied-cross-namespace-start
  caller "$namespace" rust-namespace-a both deny-register same-workflow-id
  caller "$namespace" rust-namespace-a both deny-poll same-workflow-id
done
for namespace in rust-namespace-a rust-namespace-b; do
  foreign=rust-namespace-a
  [[ "$namespace" == rust-namespace-a ]] && foreign=rust-namespace-b
  caller "$namespace" "$namespace" operator missing-describe "only-$foreign"
  caller "$namespace" "$namespace" operator missing-signal "only-$foreign"
done
if ! DURABLE_WORKFLOW_NAMESPACE_RUNS="$(observer park)"; then
  printf '%s\n' "$DURABLE_WORKFLOW_NAMESPACE_RUNS" >&2
  exit 1
fi
export DURABLE_WORKFLOW_NAMESPACE_RUNS
printf '%s\n' "$DURABLE_WORKFLOW_NAMESPACE_RUNS"
"${compose[@]}" kill --signal SIGKILL "${workers[@]}"
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator release same-workflow-id
  caller "$namespace" "$namespace" operator release "only-$namespace"
done
observer released
"${compose[@]}" up -d --wait --no-build --force-recreate "${workers[@]}"
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator verify same-workflow-id
  caller "$namespace" "$namespace" operator verify "only-$namespace"
done
observer verify
printf 'SDK namespaces pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
