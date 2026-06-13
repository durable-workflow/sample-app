#!/usr/bin/env bash
set -euo pipefail

run_sample() {
  local name="$1"
  local command="$2"
  local expected="$3"
  local output

  printf '\n==> %s\n' "$name"
  output="$(timeout 180s docker compose exec -T app php artisan "$command")"
  printf '%s\n' "$output"

  if [[ ! "$output" =~ $expected ]]; then
    printf 'compose-smoke: %s output did not match /%s/\n' "$name" "$expected" >&2
    return 1
  fi
}

wait_for_db() {
  # Verify the app container can actually open a PDO connection to MySQL
  # before any migration runs. The compose healthcheck probes MySQL itself,
  # but a positive PDO::__construct() from inside `app` is the only thing
  # that proves credentials, network, and TCP listener are all ready end to end.
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
      printf 'compose-smoke: database reachable from app after %d attempt(s)\n' "$attempt"
      return 0
    fi
    sleep 2
  done

  printf 'compose-smoke: database never became reachable from app within 120s\n' >&2
  docker compose ps >&2 || true
  return 1
}

restart_worker_after_schema_refresh() {
  printf '\n==> restarting worker after schema refresh\n'
  docker compose up -d --no-deps --force-recreate --wait worker
}

docker compose ps

printf '\n==> waiting for database to accept app connections\n'
wait_for_db

printf '\n==> fresh database migrations\n'
docker compose exec -T app php artisan migrate:fresh --force
restart_worker_after_schema_refresh

run_sample "simple workflow" "app:workflow" "workflow_activity_other"
run_sample "elapsed workflow" "app:elapsed" "Elapsed Time: [0-9]+ seconds"
run_sample "microservice workflow" "app:microservice" "workflow_activity_other"
run_sample "webhook workflow" "app:webhook" "Hello world"

if [[ "${SAMPLE_APP_SMOKE_ONLY:-0}" != "1" ]]; then
  printf '\n==> full sample-app conformance surface\n'
  scripts/compose-conformance.sh
else
  printf '\ncompose-smoke: all deterministic sample workflows passed\n'
fi
