#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-namespaces.sh' \
    'Runs published Rust clients/workers in two namespaces with identical queue and type names.' \
    'Checks role credentials, denied reads/mutations/polls and original-run recovery after SIGKILL.' \
    'Requires authenticated history principals despite forged identity fields on real SDK requests.' \
    'Rotates operator/worker credentials during process absence and rejects the old credentials through Rust SDK calls.' \
    'Requires Docker Compose and exact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_NAMESPACES_COMPOSE_PROJECT_NAME selects an isolated project. Project resources are removed on exit.' \
    'SDK_NAMESPACES_RESULT_DIR optionally retains actual histories, gateway receipts and process-loss records.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_NAMESPACES_COMPOSE_PROJECT_NAME:-sample-app-sdk-namespaces-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export NAMESPACE_UID="$(id -u)" NAMESPACE_GID="$(id -g)"
export RUST_NAMESPACE_A_WORKER_TOKEN=dwr_fixture_worker_rust_namespace_a_0123456789
export RUST_NAMESPACE_B_WORKER_TOKEN=dwr_fixture_worker_rust_namespace_b_0123456789
export NAMESPACE_PROOF_DIR="$(mktemp -d "${TMPDIR:-/tmp}/sdk-namespaces.XXXXXX")"
compose=(docker compose --project-directory "$repo_root/polyglot" \
  -f "$repo_root/polyglot/docker-compose.yml" -f "$repo_root/polyglot/docker-compose.namespaces.yml")
workers=(rust-namespace-a rust-namespace-b)
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  rmdir "$NAMESPACE_PROOF_DIR"
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi
cleanup() {
  local code=$?
  if [[ "$code" != 0 ]]; then "${compose[@]}" logs --no-color --timestamps || true; fi
  if [[ -n "${SDK_NAMESPACES_RESULT_DIR:-}" ]]; then
    mkdir -p "$SDK_NAMESPACES_RESULT_DIR"
    cp -R "$NAMESPACE_PROOF_DIR/." "$SDK_NAMESPACES_RESULT_DIR/"
  fi
  "${compose[@]}" down --volumes --remove-orphans || return 1
  local suffix image
  for suffix in rust-workflow-worker smoke; do
    image="${COMPOSE_PROJECT_NAME}-${suffix}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then docker image rm --no-prune "$image" || return 1; fi
  done
  find "$NAMESPACE_PROOF_DIR" -xdev -depth -delete
  return "$code"
}
trap cleanup EXIT
printf 'SDK namespaces start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'Runner commit: %s\n' "$(git -C "$repo_root" rev-parse HEAD)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
"${compose[@]}" build smoke rust-workflow-worker
"${compose[@]}" run --rm --no-deps smoke \
  python -m unittest discover -s /app/scripts -p test_sdk_namespaces.py
"${compose[@]}" run --rm --no-deps smoke \
  python -m unittest discover -s /app/scripts -p test_principal_gateway.py
"${compose[@]}" pull --policy always bootstrap server timer-queue
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'
observer() {
  "${compose[@]}" run --rm --no-deps -e DURABLE_WORKFLOW_NAMESPACE_RUNS \
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
  if [[ "$role" == worker-as-control ]]; then
    control="dwr_fixture_worker_${credential_namespace//-/_}_0123456789"
  fi
  if [[ "$role" == operator-as-worker ]]; then
    worker="dwr_fixture_operator_${credential_namespace//-/_}_0123456789"
  fi
  if [[ "${namespace_credentials_rotated:-false}" == true && "$phase" != revoked-* ]]; then
    [[ -z "$control" ]] || control="${control}_rotated"
    [[ -z "$worker" ]] || worker="${worker}_rotated"
  fi
  "${compose[@]}" run --rm --no-deps --user "$NAMESPACE_UID:$NAMESPACE_GID" \
    -e DURABLE_WORKFLOW_SERVER_URL=http://principal-gateway:8083 \
    -e DURABLE_WORKFLOW_AUTH_TOKEN= \
    -e "DURABLE_WORKFLOW_CONTROL_TOKEN=$control" -e "DURABLE_WORKFLOW_WORKER_TOKEN=$worker" \
    -e "DURABLE_WORKFLOW_NAMESPACE=$namespace" -e POLYGLOT_RUST_MODE=namespace-client \
    -e "DURABLE_WORKFLOW_NAMESPACE_PHASE=$phase" -e "DURABLE_WORKFLOW_NAMESPACE_ID=$id" \
    rust-workflow-worker
}
observer setup
"${compose[@]}" up -d --wait --no-build principal-gateway
"${compose[@]}" up -d --wait --no-build "${workers[@]}"
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator start "namespace-run-$namespace"
  caller "$namespace" "$namespace" operator start "only-$namespace"
done
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" both routed "namespace-run-$namespace"
  caller "$namespace" "$namespace" worker missing-control "namespace-run-$namespace"
  caller "$namespace" "$namespace" worker-as-control deny-describe "namespace-run-$namespace"
  # The operator role can perform diagnostic registration. Polling requires worker authority.
  caller "$namespace" "$namespace" operator missing-worker "namespace-run-$namespace"
  caller "$namespace" "$namespace" operator-as-worker deny-poll "namespace-run-$namespace"
done
# Workflow IDs are reserved across namespaces by the published Server contract.
caller rust-namespace-b rust-namespace-b operator deny-collision namespace-run-rust-namespace-a
# Namespace A credentials cannot access B, default, or an unregistered namespace.
for namespace in rust-namespace-b default missing-namespace; do
  caller "$namespace" rust-namespace-a both deny-describe "namespace-run-$namespace"
  caller "$namespace" rust-namespace-a both deny-signal "namespace-run-$namespace"
  caller "$namespace" rust-namespace-a both deny-start denied-cross-namespace-start
  caller "$namespace" rust-namespace-a both deny-register "namespace-run-$namespace"
  caller "$namespace" rust-namespace-a both deny-poll "namespace-run-$namespace"
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
printf '%s\n' "$DURABLE_WORKFLOW_NAMESPACE_RUNS" | jq -s . > "$NAMESPACE_PROOF_DIR/runs.json"
inspect_worker() {
  docker inspect "$1" | jq '.[0] | {Id,State:{Pid:.State.Pid,StartedAt:.State.StartedAt,FinishedAt:.State.FinishedAt,ExitCode:.State.ExitCode,OOMKilled:.State.OOMKilled,Running:.State.Running}}'
}
declare -A original_workers
for worker in "${workers[@]}"; do
  original_workers[$worker]="$("${compose[@]}" ps -q "$worker")"
  inspect_worker "${original_workers[$worker]}" > "$NAMESPACE_PROOF_DIR/$worker-before.json"
  jq -e '.State.Running == true and .State.Pid > 0' "$NAMESPACE_PROOF_DIR/$worker-before.json" >/dev/null
done
"${compose[@]}" kill --signal SIGKILL "${workers[@]}"
for worker in "${workers[@]}"; do
  inspect_worker "${original_workers[$worker]}" > "$NAMESPACE_PROOF_DIR/$worker-killed.json"
  jq -e '.State.ExitCode == 137 and .State.OOMKilled == false and .State.Running == false' \
    "$NAMESPACE_PROOF_DIR/$worker-killed.json" >/dev/null
done
observer rotate
namespace_credentials_rotated=true
export RUST_NAMESPACE_A_WORKER_TOKEN=dwr_fixture_worker_rust_namespace_a_0123456789_rotated
export RUST_NAMESPACE_B_WORKER_TOKEN=dwr_fixture_worker_rust_namespace_b_0123456789_rotated
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator revoked-describe "namespace-run-$namespace" \
    | tee "$NAMESPACE_PROOF_DIR/$namespace-revoked-describe.json"
  caller "$namespace" "$namespace" worker revoked-poll "namespace-run-$namespace" \
    | tee "$NAMESPACE_PROOF_DIR/$namespace-revoked-poll.json"
done
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator release "namespace-run-$namespace"
  caller "$namespace" "$namespace" operator release "only-$namespace"
done
observer released
"${compose[@]}" up -d --wait --no-build --force-recreate "${workers[@]}"
for worker in "${workers[@]}"; do
  inspect_worker "$("${compose[@]}" ps -q "$worker")" > "$NAMESPACE_PROOF_DIR/$worker-replacement.json"
  jq -e --arg old "${original_workers[$worker]}" '.Id != $old and .State.Running == true and .State.Pid > 0' \
    "$NAMESPACE_PROOF_DIR/$worker-replacement.json" >/dev/null
done
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator verify "namespace-run-$namespace"
  caller "$namespace" "$namespace" operator verify "only-$namespace"
done
observer verify
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator query "namespace-run-$namespace" \
    | tee "$NAMESPACE_PROOF_DIR/$namespace-query.json"
  caller "$namespace" "$namespace" operator start-failure "principal-failure-$namespace" \
    | tee "$NAMESPACE_PROOF_DIR/start-principal-failure-$namespace.json"
  caller "$namespace" "$namespace" operator start-cancel "principal-cancel-$namespace" \
    | tee "$NAMESPACE_PROOF_DIR/start-principal-cancel-$namespace.json"
done
observer operations-park
for namespace in rust-namespace-a rust-namespace-b; do
  caller "$namespace" "$namespace" operator cancel "principal-cancel-$namespace" \
    | tee "$NAMESPACE_PROOF_DIR/$namespace-cancel.json"
  caller "$namespace" "$namespace" operator verify-failed "principal-failure-$namespace"
  caller "$namespace" "$namespace" operator verify-cancelled "principal-cancel-$namespace"
done
observer operations-verify
printf 'SDK namespaces pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
