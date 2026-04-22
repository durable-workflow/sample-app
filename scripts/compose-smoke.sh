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

docker compose ps

printf '\n==> fresh database migrations\n'
docker compose exec -T app php artisan migrate:fresh --force

run_sample "simple workflow" "app:workflow" "workflow_activity_other"
run_sample "elapsed workflow" "app:elapsed" "Elapsed Time: [0-9]+ seconds"
run_sample "microservice workflow" "app:microservice" "workflow_activity_other"
run_sample "webhook workflow" "app:webhook" "Hello world"

printf '\ncompose-smoke: all deterministic sample workflows passed\n'
