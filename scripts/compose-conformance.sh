#!/usr/bin/env bash
set -euo pipefail

wait_for_db() {
  local attempt

  for attempt in $(seq 1 60); do
    if docker compose exec -T app php -r '
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
  docker compose ps >&2 || true
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

  printf '\n==> refreshing app and worker containers with conformance credentials\n'
  docker compose up -d --no-deps --force-recreate --wait app worker
}

rebuild_services_for_artifact_tuple() {
  printf '\n==> rebuilding app and worker containers with resolved artifact tuple\n'
  docker compose up -d --build --wait app worker
}

restart_worker_after_schema_refresh() {
  printf '\n==> restarting worker after schema refresh\n'
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

printf '\n==> resolving current published artifact tuple\n'
resolve_artifacts

rebuild_services_for_artifact_tuple
refresh_services_for_conformance_env

printf '\n==> waiting for database to accept app connections\n'
wait_for_db

printf '\n==> fresh database migrations\n'
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

docker compose exec -T \
  -e SAMPLE_APP_COMMIT="${sample_app_commit}" \
  -e DURABLE_SERVER_IMAGE \
  -e DURABLE_WORKFLOW_CLI_VERSION \
  -e DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  -e DURABLE_WORKFLOW_PHP_SDK_VERSION \
  -e DURABLE_WORKFLOW_WATERLINE_VERSION \
  -e OPENAI_API_KEY \
  app php artisan app:conformance --app-url="${app_url}" "${args[@]}"
