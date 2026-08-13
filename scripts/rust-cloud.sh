#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
manifest="$repo_root/rust-cloud/Cargo.toml"
lock_file="$repo_root/rust-cloud/Cargo.lock"
task_queue="${DURABLE_WORKFLOW_TASK_QUEUE:-rust-cloud-quickstart}"
mode="${1:-run}"

case "$mode" in
  run|worker)
    ;;
  *)
    printf 'usage: %s [run|worker]\n' "${BASH_SOURCE[0]}" >&2
    exit 2
    ;;
esac

require_env() {
  local name="$1"
  if [[ -z "${!name:-}" ]]; then
    printf 'rust-cloud: %s must be set; follow the Rust Cloud quickstart environment block\n' "$name" >&2
    exit 2
  fi
}

require_command() {
  local name="$1"
  if ! command -v "$name" >/dev/null 2>&1; then
    printf 'rust-cloud: %s is required by the prepared Codespaces path\n' "$name" >&2
    exit 2
  fi
}

for name in \
  DURABLE_WORKFLOW_RUNTIME_URL \
  DURABLE_WORKFLOW_RUNTIME_NAMESPACE \
  DURABLE_WORKFLOW_WORKER_TOKEN
do
  require_env "$name"
done

if [[ "$mode" == "run" ]]; then
  require_env DURABLE_WORKFLOW_CLIENT_TOKEN
fi

runtime_url="$DURABLE_WORKFLOW_RUNTIME_URL"
runtime_namespace="$DURABLE_WORKFLOW_RUNTIME_NAMESPACE"
worker_token="$DURABLE_WORKFLOW_WORKER_TOKEN"
client_token="${DURABLE_WORKFLOW_CLIENT_TOKEN:-}"
export -n runtime_url runtime_namespace worker_token client_token task_queue
unset \
  DURABLE_WORKFLOW_CLIENT_TOKEN \
  DURABLE_WORKFLOW_RUNTIME_NAMESPACE \
  DURABLE_WORKFLOW_RUNTIME_URL \
  DURABLE_WORKFLOW_TASK_QUEUE \
  DURABLE_WORKFLOW_WORKER_TOKEN

if [[ "$runtime_url" == */api || \
      ! "$runtime_url" =~ /api/runtime/v1/namespaces/[^/]+/?$ ]]; then
  printf '%s\n' \
    'rust-cloud: DURABLE_WORKFLOW_RUNTIME_URL must be the complete namespace runtime URL ending at the namespace identifier, without a terminal /api' >&2
  exit 2
fi

require_command cargo

artifact_assignments="$("$repo_root/scripts/resolve-current-artifacts.sh")"
current_rust_sdk_version="$(
  printf '%s\n' "$artifact_assignments" \
    | sed -n 's/^DURABLE_WORKFLOW_RUST_SDK_VERSION=//p'
)"
current_cli_version="$(
  printf '%s\n' "$artifact_assignments" \
    | sed -n 's/^DURABLE_WORKFLOW_CLI_VERSION=//p'
)"
manifest_rust_sdk_version="$(
  sed -n 's/^durable-workflow = "=\([^"]*\)"$/\1/p' "$manifest"
)"
if [[ -z "$current_rust_sdk_version" || \
      "$manifest_rust_sdk_version" != "$current_rust_sdk_version" ]]; then
  printf 'rust-cloud: checked-in Rust SDK %s does not match the current supported release %s; update the Sample App checkout\n' \
    "${manifest_rust_sdk_version:-missing}" "${current_rust_sdk_version:-unresolved}" >&2
  exit 2
fi

if [[ ! -f "$lock_file" ]]; then
  printf '%s\n' \
    'rust-cloud: the supported dependency lock is missing; update the Sample App checkout' >&2
  exit 2
fi

cargo_target_dir="${CARGO_TARGET_DIR:-$repo_root/rust-cloud/target}"
if [[ "$cargo_target_dir" != /* ]]; then
  cargo_target_dir="$PWD/$cargo_target_dir"
fi
worker_binary="$cargo_target_dir/debug/durable-workflow-rust-cloud-quickstart"

case "$mode" in
  worker)
    printf '==> Rust Cloud: development worker on queue %s\n' "$task_queue"
    printf '%s\n' '==> Rust Cloud: building the development worker'
    cargo build --locked --manifest-path "$manifest"
    exec env \
      DURABLE_WORKFLOW_RUNTIME_URL="$runtime_url" \
      DURABLE_WORKFLOW_RUNTIME_NAMESPACE="$runtime_namespace" \
      DURABLE_WORKFLOW_TASK_QUEUE="$task_queue" \
      DURABLE_WORKFLOW_WORKER_TOKEN="$worker_token" \
      "$worker_binary"
    ;;
  run)
    require_command dw
    reported_cli_version="$(dw --version)"
    if [[ -z "$current_cli_version" || "$reported_cli_version" != *"$current_cli_version"* ]]; then
      printf 'rust-cloud: bundled CLI does not match the current supported release %s: %s\n' \
        "${current_cli_version:-unresolved}" "$reported_cli_version" >&2
      exit 2
    fi
    ;;
esac

workflow_id="rust-cloud-$(date +%s)"
evidence_dir="$repo_root/storage/app/rust-cloud/$workflow_id"
mkdir -p "$evidence_dir"

printf '%s\n' '==> Rust Cloud: checking exact SDK and CLI versions'
cargo tree --locked --manifest-path "$manifest" -p durable-workflow --depth 0 \
  | tee "$evidence_dir/sdk-version.txt"
printf '%s\n' "$reported_cli_version" | tee "$evidence_dir/cli-version.txt"

printf '%s\n' '==> Rust Cloud: building the development worker'
cargo build --locked --manifest-path "$manifest"
DURABLE_WORKFLOW_RUNTIME_URL="$runtime_url" \
DURABLE_WORKFLOW_RUNTIME_NAMESPACE="$runtime_namespace" \
DURABLE_WORKFLOW_TASK_QUEUE="$task_queue" \
DURABLE_WORKFLOW_WORKER_TOKEN="$worker_token" \
  "$worker_binary" >"$evidence_dir/worker.log" 2>&1 &
worker_pid=$!

cleanup() {
  kill -INT "$worker_pid" 2>/dev/null || true
  wait "$worker_pid" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

printf '%s\n' '==> Rust Cloud: checking worker process health'
sleep 1
if ! kill -0 "$worker_pid" 2>/dev/null; then
  sed -n '1,160p' "$evidence_dir/worker.log" >&2
  printf '%s\n' 'rust-cloud: worker exited before workflow start' >&2
  exit 1
fi

printf '==> Rust Cloud: starting workflow %s\n' "$workflow_id"
if ! DURABLE_WORKFLOW_CLIENT_TOKEN="$client_token" \
  dw workflow:start \
    --server="$runtime_url" \
    --namespace="$runtime_namespace" \
    --token="$client_token" \
    --type=sample.rust-cloud.greeter \
    --task-queue="$task_queue" \
    --workflow-id="$workflow_id" \
    --input='["Cloud"]' \
    --wait --json | tee "$evidence_dir/result.json"; then
  sed -n '1,160p' "$evidence_dir/worker.log" >&2
  printf '%s\n' \
    'rust-cloud: workflow did not complete; check the bounded CLI diagnostic and worker process output for the configured queue' >&2
  exit 1
fi

cleanup
trap - EXIT INT TERM

if ! grep -q 'shutdown=clean' "$evidence_dir/worker.log"; then
  sed -n '1,160p' "$evidence_dir/worker.log" >&2
  printf '%s\n' 'rust-cloud: worker did not confirm clean shutdown' >&2
  exit 1
fi

printf 'Rust Cloud completed workflow_id=%s task_queue=%s\n' "$workflow_id" "$task_queue"
printf 'Version and result evidence: %s\n' "$evidence_dir"
if [[ -n "${DURABLE_WORKFLOW_MANAGED_WATERLINE_URL:-}" ]]; then
  printf 'Inspect the completed run in Managed Waterline: %s (workflow %s)\n' \
    "$DURABLE_WORKFLOW_MANAGED_WATERLINE_URL" "$workflow_id"
else
  printf '%s\n' \
    'Set DURABLE_WORKFLOW_MANAGED_WATERLINE_URL to print the managed operator link for this namespace.'
fi
