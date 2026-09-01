#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="$repo_root/polyglot/docker-compose.yml"

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  printf '%s\n' 'PolyglotWorkflow requires Docker Compose from the prepared Codespaces image.' >&2
  exit 1
fi

printf '%s\n' '==> PolyglotWorkflow: resolving current installable artifacts'
artifact_assignments="$("$repo_root/scripts/resolve-current-artifacts.sh")"
while IFS= read -r assignment; do
  [[ -n "$assignment" ]] || continue
  export "$assignment"
done <<< "$artifact_assignments"

project_name="${POLYGLOT_COMPOSE_PROJECT_NAME:-sample-app-polyglot-demo}"
if [[ ! "$project_name" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  printf 'POLYGLOT_COMPOSE_PROJECT_NAME must contain only lowercase letters, digits, underscores, and hyphens: %s\n' \
    "$project_name" >&2
  exit 1
fi
export COMPOSE_PROJECT_NAME="$project_name"

compose=(docker compose --project-directory "$repo_root/polyglot" -f "$compose_file")

case "${1:-}" in
  '') ;;
  down)
    printf '==> PolyglotWorkflow: removing Compose project %s\n' "$COMPOSE_PROJECT_NAME"
    "${compose[@]}" down --volumes --remove-orphans
    exit 0
    ;;
  *)
    printf 'Usage: %s [down]\n' "${0##*/}" >&2
    exit 2
    ;;
esac

printf '==> PolyglotWorkflow: building PHP %s, Python %s, and Rust %s workers\n' \
  "$DURABLE_WORKFLOW_PHP_SDK_VERSION" \
  "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION" \
  "$DURABLE_WORKFLOW_RUST_SDK_VERSION"
"${compose[@]}" build \
  polyglot-workflow-worker \
  python-activity-worker \
  rust-activity-worker \
  demo

printf '==> PolyglotWorkflow: starting Durable Workflow Server %s and three runtime workers\n' \
  "$DURABLE_SERVER_IMAGE"
# Refresh the product image so the declared artifact tuple cannot resolve to a
# stale local cache entry.
"${compose[@]}" pull --policy always bootstrap server
"${compose[@]}" pull --policy missing mysql redis
"${compose[@]}" up \
  --detach \
  --no-build \
  --wait \
  --wait-timeout "${POLYGLOT_COMPOSE_WAIT_SECONDS:-180}" \
  server \
  polyglot-workflow-worker \
  python-activity-worker \
  rust-activity-worker

printf '%s\n' '==> PolyglotWorkflow: running one PHP -> Python -> Rust workflow execution'
"${compose[@]}" run --rm --no-deps demo

printf 'PolyglotWorkflow stack remains available in Compose project %s.\n' "$COMPOSE_PROJECT_NAME"
printf 'Stop it with: POLYGLOT_COMPOSE_PROJECT_NAME=%s scripts/polyglot.sh down\n' \
  "$COMPOSE_PROJECT_NAME"
