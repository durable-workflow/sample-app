#!/usr/bin/env bash
set -euo pipefail

compose_diagnostics() {
  local context="$1"
  local lines="${SAMPLE_APP_DIAGNOSTIC_LOG_LINES:-160}"

  printf '\ncompose-conformance: diagnostics after %s\n' "$context" >&2
  docker compose ps >&2 || true
  docker compose logs --no-color --timestamps --tail="$lines" app worker mysql redis >&2 || true
}

run_step() {
  local name="$1"
  local timeout_seconds="$2"
  shift 2

  printf '\n==> %s\n' "$name"

  set +e
  timeout "${timeout_seconds}s" "$@"
  local status=$?
  set -e

  if [[ "$status" -ne 0 ]]; then
    if [[ "$status" -eq 124 ]]; then
      printf 'compose-conformance: %s timed out after %ss\n' "$name" "$timeout_seconds" >&2
    else
      printf 'compose-conformance: %s exited with status %d\n' "$name" "$status" >&2
    fi

    compose_diagnostics "$name"

    return "$status"
  fi
}

wait_for_db() {
  local attempt
  local probe_timeout_seconds="${SAMPLE_APP_DB_PROBE_TIMEOUT_SECONDS:-10}"

  for attempt in $(seq 1 60); do
    if timeout "${probe_timeout_seconds}s" docker compose exec -T app php -r '
      $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s",
        getenv("DB_HOST") ?: "mysql",
        getenv("DB_PORT") ?: "3306",
        getenv("DB_DATABASE") ?: "sample"
      );
      try {
        new PDO($dsn, getenv("DB_USERNAME") ?: "laravel", getenv("DB_PASSWORD") ?: "password", [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_TIMEOUT => 2,
        ]);
        exit(0);
      } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
      }
    ' >/dev/null 2>&1; then
      printf 'compose-conformance: database reachable from app after %d attempt(s)\n' "$attempt"
      return 0
    fi
    sleep 2
  done

  printf 'compose-conformance: database never became reachable from app within 120s\n' >&2
  compose_diagnostics "database readiness"
  return 1
}

resolve_artifacts() {
  local assignment

  while IFS= read -r assignment; do
    export "$assignment"
    printf 'compose-conformance: %s\n' "$assignment"
  done < <(scripts/resolve-current-artifacts.sh)
}

load_env_value() {
  local name="$1"
  local file="$2"
  local line
  local value

  if [[ -n "${!name:-}" || ! -f "$file" ]]; then
    return 0
  fi

  line="$(grep -E "^[[:space:]]*${name}=" "$file" | tail -n 1 || true)"
  if [[ -z "$line" ]]; then
    return 0
  fi

  value="${line#*=}"
  value="${value%$'\r'}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"

  if [[ "$value" == \"*\" && "$value" == *\" ]]; then
    value="${value:1:${#value}-2}"
  elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
    value="${value:1:${#value}-2}"
  fi

  if [[ -n "$value" ]]; then
    export "$name=$value"
    printf 'compose-conformance: loaded %s from env file\n' "$name"
  fi
}

load_conformance_env() {
  local configured="${SAMPLE_APP_CONFORMANCE_ENV_FILE:-}"
  local file
  local dir
  local candidates=()

  if [[ -n "$configured" ]]; then
    candidates+=("$configured")
  fi

  dir="$PWD"
  while [[ -n "$dir" && "$dir" != "/" ]]; do
    candidates+=("$dir/.env")
    dir="$(dirname "$dir")"
  done
  candidates+=("/.env")

  for file in "${candidates[@]}"; do
    load_env_value OPENAI_API_KEY "$file"
  done
}

refresh_services_for_conformance_env() {
  if [[ -z "${OPENAI_API_KEY:-}" ]]; then
    return 0
  fi

  run_step \
    "refreshing app and worker containers with conformance credentials" \
    "${SAMPLE_APP_SERVICE_REFRESH_TIMEOUT_SECONDS:-300}" \
    docker compose up -d --no-deps --force-recreate --wait app worker
}

rebuild_services_for_artifact_tuple() {
  run_step \
    "rebuilding app and worker containers with resolved artifact tuple" \
    "${SAMPLE_APP_SERVICE_REBUILD_TIMEOUT_SECONDS:-600}" \
    docker compose up -d --build --wait app worker
}

restart_worker_after_schema_refresh() {
  run_step \
    "restarting worker after schema refresh" \
    "${SAMPLE_APP_WORKER_RESTART_TIMEOUT_SECONDS:-180}" \
    docker compose up -d --no-deps --force-recreate --wait worker
}

load_conformance_env

docker compose ps

sample_app_commit="${SAMPLE_APP_COMMIT:-}"
if [[ -z "${sample_app_commit}" ]]; then
  if ! sample_app_commit="$(git rev-parse HEAD 2>/dev/null)"; then
    printf 'compose-conformance: unable to determine the sample-app commit; set SAMPLE_APP_COMMIT explicitly\n' >&2
    exit 1
  fi
fi
export SAMPLE_APP_COMMIT="$sample_app_commit"

printf '\n==> resolving current published artifact tuple\n'
resolve_artifacts

rebuild_services_for_artifact_tuple
refresh_services_for_conformance_env

printf '\n==> waiting for database to accept app connections\n'
wait_for_db

run_step \
  "fresh database migrations" \
  "${SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS:-180}" \
  docker compose exec -T app php artisan migrate:fresh --force
restart_worker_after_schema_refresh

printf '\n==> full sample-app conformance\n'
args=("$@")
if [[ "${SAMPLE_APP_CONFORMANCE_ALLOW_SKIPS:-0}" != "1" ]]; then
  args=(--strict "${args[@]}")
else
  args=(--allow-skips "${args[@]}")
fi

app_url="${SAMPLE_APP_CONFORMANCE_URL:-http://app:8000}"
metadata_path="${SAMPLE_APP_CONFORMANCE_METADATA_PATH:-storage/app/sample-app-conformance-metadata.json}"
metadata_container_path="${SAMPLE_APP_CONFORMANCE_CONTAINER_METADATA_PATH:-storage/app/sample-app-conformance-metadata.json}"
metadata_container_abs="/app/${metadata_container_path#/}"
mkdir -p "$(dirname "$metadata_path")"

set +e
timeout "${SAMPLE_APP_CONFORMANCE_TIMEOUT_SECONDS:-1800}s" docker compose exec -T \
  -e SAMPLE_APP_COMMIT="${sample_app_commit}" \
  -e DURABLE_SERVER_IMAGE \
  -e DURABLE_WORKFLOW_CLI_VERSION \
  -e DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  -e DURABLE_WORKFLOW_RUST_SDK_VERSION \
  -e DURABLE_WORKFLOW_PHP_SDK_VERSION \
  -e DURABLE_WORKFLOW_WATERLINE_VERSION \
  -e OPENAI_API_KEY \
  app php artisan app:conformance --app-url="${app_url}" --output="${metadata_container_path}" "${args[@]}"
status=$?
set -e

if [[ "$status" -ne 0 ]]; then
  if [[ "$status" -eq 124 ]]; then
    printf 'compose-conformance: full sample-app conformance timed out after %ss\n' "${SAMPLE_APP_CONFORMANCE_TIMEOUT_SECONDS:-1800}" >&2
  else
    printf 'compose-conformance: full sample-app conformance exited with status %d\n' "$status" >&2
  fi

  compose_diagnostics "full sample-app conformance"
fi

if timeout "${SAMPLE_APP_METADATA_COPY_TIMEOUT_SECONDS:-60}s" docker compose cp "app:${metadata_container_abs}" "$metadata_path" >/dev/null; then
  printf 'compose-conformance: sample-app metadata copied to %s\n' "$metadata_path"
  printf 'compose-conformance: set DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH=%s for agent-operability validation\n' "$metadata_path"
else
  printf 'compose-conformance: unable to copy sample-app metadata from %s\n' "$metadata_container_abs" >&2
fi

exit "$status"
