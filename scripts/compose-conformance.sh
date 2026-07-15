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

now_milliseconds() {
  date +%s%3N
}

docker_storage_usage_bytes() {
  local docker_root

  docker_root="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
  if [[ -n "$docker_root" && -d "$docker_root" ]]; then
    df -Pk "$docker_root" | awk 'NR == 2 { printf "%.0f\n", $3 * 1024 }'
    return 0
  fi

  docker system df --format '{{.Size}}' 2>/dev/null | awk '
    function size_in_bytes(value, number, unit) {
      number = value
      sub(/[[:alpha:]]+$/, "", number)
      unit = value
      sub(/^[0-9.]+/, "", unit)

      if (unit == "kB") return number * 1000
      if (unit == "MB") return number * 1000 * 1000
      if (unit == "GB") return number * 1000 * 1000 * 1000
      if (unit == "TB") return number * 1000 * 1000 * 1000 * 1000
      if (unit == "KiB") return number * 1024
      if (unit == "MiB") return number * 1024 * 1024
      if (unit == "GiB") return number * 1024 * 1024 * 1024
      if (unit == "TiB") return number * 1024 * 1024 * 1024 * 1024

      return number
    }

    { total += size_in_bytes($1) }
    END { printf "%.0f\n", total }
  '
}

cleanup_setup_sampler() {
  if [[ -n "${setup_disk_sampler_pid:-}" ]]; then
    kill "$setup_disk_sampler_pid" 2>/dev/null || true
    wait "$setup_disk_sampler_pid" 2>/dev/null || true
    setup_disk_sampler_pid=""
  fi

  if [[ -n "${setup_disk_samples_path:-}" ]]; then
    rm -f "$setup_disk_samples_path"
    setup_disk_samples_path=""
  fi
}

start_setup_measurement() {
  setup_started_ms="$(now_milliseconds)"
  setup_cache_state="${SAMPLE_APP_SETUP_CACHE_STATE:-}"
  setup_disk_samples_path=""
  setup_disk_sampler_pid=""

  if [[ -z "$setup_cache_state" ]]; then
    if [[ -n "$(docker compose images -q app 2>/dev/null | head -n 1)" ]]; then
      setup_cache_state="warm-cache"
    else
      setup_cache_state="clean-cache"
    fi
  fi

  local baseline
  baseline="$(docker_storage_usage_bytes || true)"
  if [[ ! "$baseline" =~ ^[0-9]+$ ]]; then
    printf 'compose-conformance: Docker disk usage is unavailable; peak growth will be recorded as unavailable\n' >&2
    return 0
  fi

  setup_disk_samples_path="$(mktemp "${TMPDIR:-/tmp}/sample-app-setup-disk.XXXXXX")"
  printf '%s\n' "$baseline" > "$setup_disk_samples_path"

  (
    while true; do
      sleep "${SAMPLE_APP_SETUP_DISK_SAMPLE_INTERVAL_SECONDS:-5}"
      docker_storage_usage_bytes >> "$setup_disk_samples_path" 2>/dev/null || true
    done
  ) &
  setup_disk_sampler_pid=$!
}

finish_setup_measurement() {
  local completed_ms
  local current
  local peak_growth=""

  completed_ms="$(now_milliseconds)"
  SAMPLE_APP_SETUP_DURATION_MS="$((completed_ms - setup_started_ms))"

  if [[ -n "${setup_disk_sampler_pid:-}" ]]; then
    kill "$setup_disk_sampler_pid" 2>/dev/null || true
    wait "$setup_disk_sampler_pid" 2>/dev/null || true
    setup_disk_sampler_pid=""
  fi

  if [[ -n "${setup_disk_samples_path:-}" ]]; then
    current="$(docker_storage_usage_bytes || true)"
    if [[ "$current" =~ ^[0-9]+$ ]]; then
      printf '%s\n' "$current" >> "$setup_disk_samples_path"
    fi

    peak_growth="$(awk '
      NR == 1 { baseline = $1; peak = $1 }
      $1 > peak { peak = $1 }
      END {
        growth = peak - baseline
        if (growth < 0) growth = 0
        printf "%.0f\n", growth
      }
    ' "$setup_disk_samples_path")"
  fi

  SAMPLE_APP_SETUP_CACHE_STATE="$setup_cache_state"
  SAMPLE_APP_SETUP_PEAK_DISK_GROWTH_BYTES="$peak_growth"
  export SAMPLE_APP_SETUP_CACHE_STATE
  export SAMPLE_APP_SETUP_DURATION_MS
  export SAMPLE_APP_SETUP_PEAK_DISK_GROWTH_BYTES
  export SAMPLE_APP_SETUP_STACK_REUSED
  export SAMPLE_APP_SETUP_BUILD_INVOCATIONS

  printf 'compose-conformance: setup metrics cache_state=%s duration_ms=%s peak_disk_growth_bytes=%s stack_reused=%s build_invocations=%s\n' \
    "$SAMPLE_APP_SETUP_CACHE_STATE" \
    "$SAMPLE_APP_SETUP_DURATION_MS" \
    "${SAMPLE_APP_SETUP_PEAK_DISK_GROWTH_BYTES:-unavailable}" \
    "$SAMPLE_APP_SETUP_STACK_REUSED" \
    "$SAMPLE_APP_SETUP_BUILD_INVOCATIONS"

  cleanup_setup_sampler
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

rebuild_services_for_artifact_tuple() {
  run_step \
    "rebuilding app and worker containers with resolved artifact tuple" \
    "${SAMPLE_APP_SERVICE_REBUILD_TIMEOUT_SECONDS:-600}" \
    docker compose up -d --build --wait app worker
}

container_is_ready() {
  local container_id="$1"
  local running
  local health

  running="$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)"
  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$container_id" 2>/dev/null || true)"

  [[ "$running" == "true" && ( -z "$health" || "$health" == "healthy" ) ]]
}

container_env_matches() {
  local container_id="$1"
  shift
  local environment
  local name
  local expected
  local actual

  environment="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container_id" 2>/dev/null)" || return 1

  for name in "$@"; do
    expected="${!name:-}"
    actual="$(printf '%s\n' "$environment" | sed -n "s/^${name}=//p" | tail -n 1)"
    if [[ "$actual" != "$expected" ]]; then
      printf 'compose-conformance: prepared container environment differs for %s\n' "$name" >&2
      return 1
    fi
  done
}

installed_artifacts_match() {
  local service="$1"

  docker compose exec -T \
    -e DURABLE_WORKFLOW_WORKFLOW_VERSION \
    -e DURABLE_WORKFLOW_WATERLINE_VERSION \
    "$service" php -r '
      require "/app/vendor/autoload.php";

      foreach ([
        "durable-workflow/workflow" => getenv("DURABLE_WORKFLOW_WORKFLOW_VERSION"),
        "durable-workflow/waterline" => getenv("DURABLE_WORKFLOW_WATERLINE_VERSION"),
      ] as $package => $expected) {
        $actual = Composer\InstalledVersions::getPrettyVersion($package);
        if ($expected === false || ltrim((string) $actual, "v") !== ltrim($expected, "v")) {
          fwrite(STDERR, sprintf("prepared package differs for %s\n", $package));
          exit(1);
        }
      }
    ' >/dev/null
}

prepared_schema_is_current() {
  local output

  output="$(docker compose exec -T app php artisan migrate:status --no-ansi 2>&1)" || {
    printf '%s\n' "$output" >&2
    return 1
  }

  if [[ "$output" == *"Pending"* ]]; then
    printf 'compose-conformance: prepared schema has pending migrations\n' >&2
    return 1
  fi
}

prepared_stack_is_reusable() {
  local expected_app_id="${SAMPLE_APP_PREPARED_APP_CONTAINER_ID:-}"
  local expected_worker_id="${SAMPLE_APP_PREPARED_WORKER_CONTAINER_ID:-}"
  local current_app_id
  local current_worker_id

  if [[ "${SAMPLE_APP_CONFORMANCE_REUSE_PREPARED:-0}" != "1" ]]; then
    return 1
  fi

  current_app_id="$(docker compose ps -q app)"
  current_worker_id="$(docker compose ps -q worker)"
  if [[ -z "$expected_app_id" || -z "$expected_worker_id" || "$current_app_id" != "$expected_app_id" || "$current_worker_id" != "$expected_worker_id" ]]; then
    printf 'compose-conformance: prepared stack handoff does not match the current app and worker containers\n' >&2
    return 1
  fi

  if ! container_is_ready "$current_app_id" || ! container_is_ready "$current_worker_id"; then
    printf 'compose-conformance: prepared app or worker is no longer healthy\n' >&2
    return 1
  fi

  if ! container_env_matches "$current_app_id" \
    OPENAI_API_KEY \
    DURABLE_SERVER_IMAGE \
    DURABLE_WORKFLOW_CLI_VERSION \
    DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
    DURABLE_WORKFLOW_RUST_SDK_VERSION \
    DURABLE_WORKFLOW_PHP_SDK_VERSION \
    DURABLE_WORKFLOW_WORKFLOW_VERSION \
    DURABLE_WORKFLOW_WATERLINE_VERSION \
    SAMPLE_APP_COMMIT; then
    return 1
  fi

  if ! container_env_matches "$current_worker_id" \
    OPENAI_API_KEY \
    DURABLE_WORKFLOW_PHP_SDK_VERSION \
    DURABLE_WORKFLOW_WORKFLOW_VERSION \
    DURABLE_WORKFLOW_WATERLINE_VERSION; then
    return 1
  fi

  installed_artifacts_match app &&
    installed_artifacts_match worker &&
    prepared_schema_is_current
}

restart_worker_after_schema_refresh() {
  run_step \
    "restarting worker after schema refresh" \
    "${SAMPLE_APP_WORKER_RESTART_TIMEOUT_SECONDS:-180}" \
    docker compose up -d --no-deps --force-recreate --wait worker
}

load_conformance_env

docker compose ps

trap cleanup_setup_sampler EXIT
start_setup_measurement
SAMPLE_APP_SETUP_STACK_REUSED="false"
SAMPLE_APP_SETUP_BUILD_INVOCATIONS="0"

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

if prepared_stack_is_reusable; then
  printf '\n==> reusing healthy prepared stack and schema\n'
  SAMPLE_APP_SETUP_STACK_REUSED="true"
else
  if [[ "${SAMPLE_APP_CONFORMANCE_REUSE_PREPARED:-0}" == "1" ]]; then
    printf '\n==> prepared stack changed; rebuilding for the resolved tuple\n'
  fi

  SAMPLE_APP_SETUP_BUILD_INVOCATIONS="1"
  rebuild_services_for_artifact_tuple

  printf '\n==> waiting for database to accept app connections\n'
  wait_for_db

  run_step \
    "fresh database migrations" \
    "${SAMPLE_APP_MIGRATION_TIMEOUT_SECONDS:-180}" \
    docker compose exec -T app php artisan migrate:fresh --force
  restart_worker_after_schema_refresh
fi

finish_setup_measurement

if [[ "${SAMPLE_APP_CONFORMANCE_SMOKE_FIRST:-0}" == "1" ]]; then
  printf '\n==> deterministic smoke against prepared stack\n'
  prepared_app_container_id="$(docker compose ps -q app)"
  prepared_worker_container_id="$(docker compose ps -q worker)"
  run_step \
    "deterministic smoke against prepared stack" \
    "${SAMPLE_APP_SMOKE_TIMEOUT_SECONDS:-900}" \
    env \
      SAMPLE_APP_SMOKE_ONLY=1 \
      SAMPLE_APP_SMOKE_REUSE_PREPARED=1 \
      SAMPLE_APP_PREPARED_APP_CONTAINER_ID="$prepared_app_container_id" \
      SAMPLE_APP_PREPARED_WORKER_CONTAINER_ID="$prepared_worker_container_id" \
      scripts/compose-smoke.sh
fi

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
  -e DURABLE_WORKFLOW_WORKFLOW_VERSION \
  -e DURABLE_WORKFLOW_WATERLINE_VERSION \
  -e OPENAI_API_KEY \
  -e SAMPLE_APP_SETUP_CACHE_STATE \
  -e SAMPLE_APP_SETUP_DURATION_MS \
  -e SAMPLE_APP_SETUP_PEAK_DISK_GROWTH_BYTES \
  -e SAMPLE_APP_SETUP_STACK_REUSED \
  -e SAMPLE_APP_SETUP_BUILD_INVOCATIONS \
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
