#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-timers.sh [--unversioned-upgrades|--sdk-upgrades]' \
    'Runs completion, worker SIGKILL/cold replay, Server restart and cooperative cancellation for PHP/Python/Rust timers.' \
    'Runs each interruption/cancellation case for both one timer and three concurrent deadlines in one original per SDK.' \
    'Replaces compatible business helpers across all SDKs and checks pinned Python class refusal, restoration and fresh v2 runs.' \
    '--unversioned-upgrades instead runs twelve originals introducing versioning into already pending single/concurrent timers.' \
    '--sdk-upgrades instead runs twelve originals across previous/current SDK packages with unchanged application code (requires jq).' \
    'Requires Docker Compose and exact artifact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_TIMERS_COMPOSE_PROJECT_NAME selects an isolated project. All project resources are removed on exit.'
  exit 0
fi
unversioned_only=0
sdk_packages_only=0
if [[ "${1:-}" == --unversioned-upgrades && $# == 1 ]]; then
  unversioned_only=1
  shift
elif [[ "${1:-}" == --sdk-upgrades && $# == 1 ]]; then
  sdk_packages_only=1
  shift
fi
[[ $# == 0 ]] || { printf '%s\n' 'Use --help for usage.' >&2; exit 2; }

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPOSE_PROJECT_NAME="${SDK_TIMERS_COMPOSE_PROJECT_NAME:-sample-app-sdk-timers-$(date -u +%Y%m%d%H%M%S)}"
[[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || exit 2
export COMPOSE_PROFILES=timers
export POLYGLOT_TIMER_COOPERATIVE=1
export POLYGLOT_TIMER_APPLICATION=v2
export POLYGLOT_WORKER_DIAGNOSTICS=1
export DURABLE_WORKFLOW_TIMER_ID="${COMPOSE_PROJECT_NAME}"
compose=(docker compose --project-directory "$repo_root/polyglot" -f "$repo_root/polyglot/docker-compose.yml")
workers=(php-same-workflow-worker python-workflow-worker rust-workflow-worker)
if (( sdk_packages_only )); then
  command -v jq >/dev/null || { printf '%s\n' 'SDK package upgrades require jq.' >&2; exit 2; }
  compose+=(-f "$repo_root/polyglot/timers/compose-sdk-upgrades.yml")
  export DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS
  DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS=$(jq -ce '
    if type == "object" and (keys == ["php", "python", "rust"])
      and all(.[]; type == "string" and test("^[0-9]+\\.[0-9]+\\.[0-9]+$"))
    then . else error("Select one exact published baseline per SDK") end
  ' "$repo_root/polyglot/timers/sdk-upgrade-baselines.json")
  export DURABLE_WORKFLOW_TIMER_SDK_TARGETS
  DURABLE_WORKFLOW_TIMER_SDK_TARGETS=$(jq -cn \
    --arg php "${DURABLE_WORKFLOW_PHP_SDK_VERSION:?resolve the published tuple}" \
    --arg python "${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:?resolve the published tuple}" \
    --arg rust "${DURABLE_WORKFLOW_RUST_SDK_VERSION:?resolve the published tuple}" \
    '{php:$php,python:$python,rust:$rust}')
  jq -en --argjson previous "$DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS" \
    --argjson target "$DURABLE_WORKFLOW_TIMER_SDK_TARGETS" \
    'all($previous | to_entries[]; .value != $target[.key])' >/dev/null || {
      printf '%s\n' 'Previous and current SDK packages must differ for every runtime.' >&2; exit 2;
    }
  target_php_pin="${DURABLE_WORKFLOW_PHP_SDK_PIN:-}"
fi
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ]]; then
  printf 'Choose a new isolated project. %s already has containers.\n' "$COMPOSE_PROJECT_NAME" >&2
  exit 2
fi

cleanup() {
  local code=$?
  if [[ "$code" != 0 ]]; then
    "${compose[@]}" ps --all || true
    "${compose[@]}" logs --no-color --timestamps || true
  fi
  "${compose[@]}" down --volumes --remove-orphans || return 1
  local suffix image
  local suffixes=(php-sdk-worker python-workflow-worker rust-workflow-worker smoke)
  if (( sdk_packages_only )); then
    for suffix in php-sdk-worker python-workflow-worker rust-workflow-worker; do
      suffixes+=("$suffix-previous" "$suffix-current")
    done
  fi
  for suffix in "${suffixes[@]}"; do
    image="${COMPOSE_PROJECT_NAME}-${suffix}:latest"
    if docker image inspect "$image" >/dev/null 2>&1; then
      docker image rm --no-prune "$image" || return 1
    fi
  done
  return "$code"
}
trap cleanup EXIT

package_generation() {
  local generation="$1" versions
  case "$generation" in
    previous) versions="$DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS" ;;
    current) versions="$DURABLE_WORKFLOW_TIMER_SDK_TARGETS" ;;
    *) return 2 ;;
  esac
  export SDK_TIMERS_PACKAGE_GENERATION="$generation"
  export DURABLE_WORKFLOW_TIMER_SDK_VERSIONS="$versions"
  export DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION DURABLE_WORKFLOW_RUST_SDK_VERSION
  DURABLE_WORKFLOW_PHP_SDK_VERSION=$(jq -r .php <<<"$versions")
  DURABLE_WORKFLOW_PYTHON_SDK_VERSION=$(jq -r .python <<<"$versions")
  DURABLE_WORKFLOW_RUST_SDK_VERSION=$(jq -r .rust <<<"$versions")
  export DURABLE_WORKFLOW_PHP_SDK_PIN="$target_php_pin"
  if [[ "$generation" == previous ]]; then
    export DURABLE_WORKFLOW_PHP_SDK_PIN="durable-workflow/sdk:$DURABLE_WORKFLOW_PHP_SDK_VERSION"
  fi
}

installed_packages() {
  local runtime metadata version
  for runtime in php python rust; do
    version=$(jq -r --arg runtime "$runtime" '.[$runtime]' <<<"$DURABLE_WORKFLOW_TIMER_SDK_VERSIONS")
    case "$runtime" in
      php)
        metadata=$("${compose[@]}" run --rm --no-deps --user 1000:1000 php-same-workflow-worker php -r '
          require "/app/vendor/autoload.php";
          echo json_encode(["sdk_version" => Composer\InstalledVersions::getPrettyVersion("durable-workflow/sdk"),
            "reference" => Composer\InstalledVersions::getReference("durable-workflow/sdk")]), PHP_EOL;') ;;
      python)
        metadata=$("${compose[@]}" run --rm --no-deps --user 1000:1000 python-workflow-worker python -c '
import importlib.metadata, json
print(json.dumps({"sdk_version": importlib.metadata.version("durable-workflow")}))') ;;
      rust)
        metadata=$("${compose[@]}" run --rm --no-deps --user 1000:1000 -e POLYGLOT_RUST_MODE=sdk-metadata rust-workflow-worker)
        metadata=$(jq --arg version "$version" \
          'if .sdk_identity == ("durable-workflow-rust/" + $version) then . + {sdk_version:$version}
           else error("Compiled Rust SDK differs from the selected package") end' <<<"$metadata") ;;
    esac
    jq -ce --arg version "$version" --arg runtime "$runtime" --arg generation "$SDK_TIMERS_PACKAGE_GENERATION" \
      'if .sdk_version == $version then . + {phase:"package_installed",runtime:$runtime,generation:$generation}
       else error("Installed SDK differs from the selected package") end' <<<"$metadata"
  done
}

printf 'SDK timers start: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
for name in DURABLE_SERVER_IMAGE DURABLE_WORKFLOW_PHP_SDK_VERSION DURABLE_WORKFLOW_PYTHON_SDK_VERSION \
  DURABLE_WORKFLOW_RUST_SDK_VERSION DURABLE_WORKFLOW_CLI_VERSION DURABLE_WORKFLOW_WORKFLOW_VERSION DURABLE_WORKFLOW_WATERLINE_VERSION; do
  printf '%s=%s\n' "$name" "${!name:?resolve exact published artifacts first}"
done
if (( sdk_packages_only )); then
  package_generation current
  "${compose[@]}" build smoke "${workers[@]}"
  installed_packages
  package_generation previous
  "${compose[@]}" build "${workers[@]}"
  installed_packages
else
  "${compose[@]}" build smoke "${workers[@]}"
fi
"${compose[@]}" pull --policy always bootstrap server timer-queue
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue "${workers[@]}"
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'

client() {
  "${compose[@]}" run --rm --no-deps --user 1000:1000 \
    -e DURABLE_WORKFLOW_TIMER_ID -e DURABLE_WORKFLOW_WORKER_STOPPED_AT -e DURABLE_WORKFLOW_WORKER_RESTART_AT \
    -e DURABLE_WORKFLOW_SERVER_STOPPED_AT -e DURABLE_WORKFLOW_SERVER_RESTART_AT \
    -e DURABLE_WORKFLOW_TIMER_PENDING \
    -e DURABLE_WORKFLOW_TIMER_WORKERS \
    -e DURABLE_WORKFLOW_TIMER_SDK_VERSIONS -e DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS -e DURABLE_WORKFLOW_TIMER_SDK_TARGETS \
    smoke python /app/scripts/sdk_timers.py "$@"
}

start() {
  local scenario="$1" status=0
  DURABLE_WORKFLOW_TIMER_PENDING="$(client start "$scenario")" || status=$?
  export DURABLE_WORKFLOW_TIMER_PENDING
  printf '%s\n' "$DURABLE_WORKFLOW_TIMER_PENDING"
  if (( status != 0 )); then
    client diagnose "$scenario" || true
    return "$status"
  fi
}

worker_restart() {
  local scenario="$1"
  start "$scenario"
  "${compose[@]}" kill --signal SIGKILL "${workers[@]}"
  export DURABLE_WORKFLOW_WORKER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  client fired "$scenario"
  export DURABLE_WORKFLOW_WORKER_RESTART_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  "${compose[@]}" up -d --wait --no-build "${workers[@]}"
  client verify "$scenario"
}

server_restart() {
  local scenario="$1" downtime="$2"
  start "$scenario"
  "${compose[@]}" stop --timeout 2 timer-queue server
  export DURABLE_WORKFLOW_SERVER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  sleep "$downtime"
  export DURABLE_WORKFLOW_SERVER_RESTART_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  "${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue
  client diagnose "$scenario"
  client verify "$scenario"
}

remember_workers() {
  DURABLE_WORKFLOW_TIMER_WORKERS="$(client ready "${1:-application-upgrade}")"
  export DURABLE_WORKFLOW_TIMER_WORKERS
  printf '%s\n' "$DURABLE_WORKFLOW_TIMER_WORKERS"
}

worker_generation=0
replace_workers() {
  local revision="$1" scenario="$2"
  "${compose[@]}" stop --timeout 10 "${workers[@]}"
  client retire "$scenario"
  export POLYGLOT_TIMER_APPLICATION="$revision"
  worker_generation=$((worker_generation + 1))
  export POLYGLOT_RUST_WORKER_ID="timer-$scenario-$revision-$worker_generation"
  "${compose[@]}" up -d --wait --force-recreate --no-build "${workers[@]}"
  remember_workers "$scenario"
}

application_upgrade() {
  local scenario="$1"
  replace_workers v1 "$scenario"
  start "$scenario"
  "${compose[@]}" kill --signal SIGKILL "${workers[@]}"
  replace_workers v2 "$scenario"
  client verify "$scenario"
  start "$scenario-fresh"
  client verify "$scenario-fresh"
}

class_replacement() {
  local scenario="$1"
  replace_workers v1 "$scenario"
  start "$scenario"
  "${compose[@]}" kill --signal SIGKILL "${workers[@]}"
  replace_workers v2 "$scenario"
  client fenced "$scenario"
  replace_workers v1 "$scenario"
  client verify "$scenario"
  replace_workers v2 "$scenario"
  start "$scenario-fresh"
  client verify "$scenario-fresh"
}

replace_package_workers() {
  local generation="$1" scenario="$2"
  "${compose[@]}" stop --timeout 10 "${workers[@]}"
  client retire "$scenario"
  package_generation "$generation"
  worker_generation=$((worker_generation + 1))
  export POLYGLOT_RUST_WORKER_ID="timer-$scenario-$generation-$worker_generation"
  export DURABLE_WORKFLOW_WORKER_RESTART_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  "${compose[@]}" up -d --wait --force-recreate --no-build "${workers[@]}"
  remember_workers "$scenario"
}

package_upgrade() {
  local scenario="$1"
  replace_package_workers previous "$scenario"
  start "$scenario"
  "${compose[@]}" kill --signal SIGKILL "${workers[@]}"
  export DURABLE_WORKFLOW_WORKER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  client fired "$scenario"
  replace_package_workers current "$scenario"
  client verify "$scenario"
  start "$scenario-fresh"
  client verify "$scenario-fresh"
}

if (( sdk_packages_only )); then
  remember_workers sdk-upgrade
  package_upgrade sdk-upgrade
  package_upgrade concurrent-sdk-upgrade
elif (( unversioned_only )); then
  remember_workers unversioned-upgrade
  application_upgrade unversioned-upgrade
  application_upgrade concurrent-unversioned-upgrade
else
  start completion
  client verify completion

  start concurrent
  client verify concurrent

  worker_restart worker-restart
  server_restart server-restart 32

  start cancellation
  client verify cancellation

  worker_restart concurrent-worker-restart
  server_restart concurrent-server-restart 62

  start concurrent-cancellation
  client verify concurrent-cancellation

  remember_workers
  application_upgrade application-upgrade
  application_upgrade concurrent-application-upgrade
  class_replacement class-replacement
  class_replacement concurrent-class-replacement
fi
printf 'SDK timers pass: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
