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

printf '\n==> waiting for database to accept app connections\n'
wait_for_db

printf '\n==> fresh database migrations\n'
docker compose exec -T app php artisan migrate:fresh --force

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
  app php artisan app:conformance --app-url="${app_url}" "${args[@]}"
