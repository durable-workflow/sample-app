#!/usr/bin/env bash
set -euo pipefail

compose_diagnostics() {
  local context="$1"
  local lines="${SAMPLE_APP_DIAGNOSTIC_LOG_LINES:-160}"

  printf '\ncompose-smoke: diagnostics after %s\n' "$context" >&2
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
      printf 'compose-smoke: %s timed out after %ss\n' "$name" "$timeout_seconds" >&2
    else
      printf 'compose-smoke: %s exited with status %d\n' "$name" "$status" >&2
    fi

    compose_diagnostics "$name"

    return "$status"
  fi
}

run_sample() {
  local name="$1"
  local command="$2"
  local expected="$3"
  local output
  local status
  local timeout_seconds="${SAMPLE_APP_SAMPLE_TIMEOUT_SECONDS:-180}"

  printf '\n==> %s\n' "$name"

  set +e
  output="$(timeout "${timeout_seconds}s" docker compose exec -T app php artisan "$command" 2>&1)"
  status=$?
  set -e

  printf '%s\n' "$output"

  if [[ "$status" -ne 0 ]]; then
    if [[ "$status" -eq 124 ]]; then
      printf 'compose-smoke: %s timed out after %ss while running php artisan %s\n' "$name" "$timeout_seconds" "$command" >&2
    else
      printf 'compose-smoke: %s exited with status %d while running php artisan %s\n' "$name" "$status" "$command" >&2
    fi

    compose_diagnostics "$name"

    return "$status"
  fi

  if [[ ! "$output" =~ $expected ]]; then
    printf 'compose-smoke: %s output did not match /%s/\n' "$name" "$expected" >&2
    compose_diagnostics "$name"
    return 1
  fi
}

wait_for_db() {
  # Verify the app container can actually open a PDO connection to MySQL
  # before any migration runs. The compose healthcheck probes MySQL itself,
  # but a positive PDO::__construct() from inside `app` is the only thing
  # that proves credentials, network, and TCP listener are all ready end to end.
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
      printf 'compose-smoke: database reachable from app after %d attempt(s)\n' "$attempt"
      return 0
    fi
    sleep 2
  done

  printf 'compose-smoke: database never became reachable from app within 120s\n' >&2
  compose_diagnostics "database readiness"
  return 1
}

restart_worker_after_schema_refresh() {
  run_step \
    "restarting worker after schema refresh" \
    "${SAMPLE_APP_WORKER_RESTART_TIMEOUT_SECONDS:-180}" \
    docker compose up -d --no-deps --force-recreate --wait worker
}

docker compose ps

printf '\n==> waiting for database to accept app connections\n'
wait_for_db

run_step \
  "fresh database migrations" \
  "${SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS:-180}" \
  docker compose exec -T app php artisan migrate:fresh --force
restart_worker_after_schema_refresh

run_sample "simple workflow" "app:workflow" "workflow_activity_other"
run_sample "elapsed workflow" "app:elapsed" "Elapsed Time: [0-9]+ seconds"
run_sample "microservice workflow" "app:microservice" "workflow_activity_other"
run_sample "webhook workflow" "app:webhook" "Hello world"

if [[ "${SAMPLE_APP_CONFORMANCE_AFTER_SMOKE:-1}" == "1" && "${SAMPLE_APP_SMOKE_ONLY:-0}" != "1" ]]; then
  printf '\n==> full sample-app conformance surface\n'
  set +e
  timeout "${SAMPLE_APP_CONFORMANCE_AFTER_SMOKE_TIMEOUT_SECONDS:-1800}s" scripts/compose-conformance.sh
  status=$?
  set -e

  if [[ "$status" -ne 0 ]]; then
    if [[ "$status" -eq 124 ]]; then
      printf 'compose-smoke: full sample-app conformance surface timed out after %ss\n' "${SAMPLE_APP_CONFORMANCE_AFTER_SMOKE_TIMEOUT_SECONDS:-1800}" >&2
    else
      printf 'compose-smoke: full sample-app conformance surface exited with status %d\n' "$status" >&2
    fi

    compose_diagnostics "full sample-app conformance surface"

    exit "$status"
  fi
else
  printf '\ncompose-smoke: all deterministic sample workflows passed\n'
fi
