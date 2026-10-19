#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-timers.sh [--unversioned-upgrades|--sdk-upgrades|--sdk-application-upgrades|--sdk-activity-upgrades]' \
    'Runs completion, worker SIGKILL/cold replay, Server restart and cooperative cancellation for PHP/Python/Rust timers.' \
    'Runs each interruption/cancellation case for both one timer and three concurrent deadlines in one original per SDK.' \
    'Replaces compatible business helpers across all SDKs and checks pinned Python class refusal, restoration and fresh v2 runs.' \
    '--unversioned-upgrades instead runs twelve originals introducing versioning into already pending single/concurrent timers.' \
    '--sdk-upgrades instead runs twelve originals across previous/current SDK packages with unchanged application code (requires Python 3).' \
    '--sdk-application-upgrades instead changes both SDK packages and versioned application helpers in twelve originals (requires Python 3).' \
    '--sdk-activity-upgrades selects the separate eighteen-original pending-activity experiment (see scripts/sdk-activity-upgrades.sh).' \
    'Requires Docker Compose and exact artifact assignments from scripts/resolve-current-artifacts.sh.' \
    'SDK_TIMERS_COMPOSE_PROJECT_NAME selects an isolated project. All project resources are removed on exit.'
  exit 0
fi
unversioned_only=0
sdk_packages_only=0
sdk_application_upgrade=0
sdk_activity_upgrade=0
if [[ "${1:-}" == --unversioned-upgrades && $# == 1 ]]; then
  unversioned_only=1
  shift
elif [[ "${1:-}" == --sdk-upgrades && $# == 1 ]]; then
  sdk_packages_only=1
  shift
elif [[ "${1:-}" == --sdk-application-upgrades && $# == 1 ]]; then
  sdk_packages_only=1
  sdk_application_upgrade=1
  shift
elif [[ "${1:-}" == --sdk-activity-upgrades && $# == 1 ]]; then
  sdk_packages_only=1
  sdk_activity_upgrade=1
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

package_json() {
  python3 - "$@" <<'PY'
import json
import os
from pathlib import Path
import re
import sys

runtimes = ("php", "python", "rust")

def versions(value):
    if (not isinstance(value, dict) or set(value) != set(runtimes)
            or any(not isinstance(version, str) or not version.strip() for version in value.values())):
        raise SystemExit("Select one exact package version per runtime")
    return value

operation = sys.argv[1]
if operation == "baseline":
    result = versions(json.loads(Path(sys.argv[2]).read_text()))
    if not all(re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", value) for value in result.values()):
        raise SystemExit("Select stable published SDK baselines")
elif operation == "targets":
    result = versions({runtime: os.environ.get(f"DURABLE_WORKFLOW_{runtime.upper()}_SDK_VERSION")
                       for runtime in runtimes})
elif operation == "different":
    previous = versions(json.loads(os.environ["DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS"]))
    target = versions(json.loads(os.environ["DURABLE_WORKFLOW_TIMER_SDK_TARGETS"]))
    if any(previous[runtime] == target[runtime] for runtime in runtimes):
        raise SystemExit("Previous and current SDK packages must differ for every runtime")
    sys.exit(0)
elif operation == "get":
    print(versions(json.loads(sys.argv[3]))[sys.argv[2]])
    sys.exit(0)
elif operation == "installed":
    runtime = sys.argv[2]
    selected = versions(json.loads(os.environ["DURABLE_WORKFLOW_TIMER_SDK_VERSIONS"]))[runtime]
    result = json.loads(sys.argv[3])
    if not isinstance(result, dict):
        raise SystemExit("Package metadata must be a JSON object")
    if runtime == "rust":
        if result.get("sdk_identity") != f"durable-workflow-rust/{selected}":
            raise SystemExit("Compiled Rust SDK differs from the selected package")
        result["sdk_version"] = selected
    if result.get("sdk_version") != selected:
        raise SystemExit("Installed SDK differs from the selected package")
    result.update(phase="package_installed", runtime=runtime,
                  generation=os.environ["SDK_TIMERS_PACKAGE_GENERATION"])
else:
    raise SystemExit("Unknown SDK package metadata operation")
print(json.dumps(result, sort_keys=True))
PY
}

if (( sdk_packages_only )); then
  command -v python3 >/dev/null || { printf '%s\n' 'SDK package upgrades require Python 3.' >&2; exit 2; }
  compose+=(-f "$repo_root/polyglot/timers/compose-sdk-upgrades.yml")
  export DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS
  DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS=$(package_json baseline "$repo_root/polyglot/timers/sdk-upgrade-baselines.json")
  export DURABLE_WORKFLOW_TIMER_SDK_TARGETS
  DURABLE_WORKFLOW_TIMER_SDK_TARGETS=$(package_json targets)
  package_json different
  target_php_pin="${DURABLE_WORKFLOW_PHP_SDK_PIN:-}"
fi
if (( sdk_activity_upgrade )); then
  compose+=(-f "$repo_root/polyglot/activities/compose-sdk-upgrades.yml")
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
  DURABLE_WORKFLOW_PHP_SDK_VERSION=$(package_json get php "$versions")
  DURABLE_WORKFLOW_PYTHON_SDK_VERSION=$(package_json get python "$versions")
  DURABLE_WORKFLOW_RUST_SDK_VERSION=$(package_json get rust "$versions")
  export DURABLE_WORKFLOW_PHP_SDK_PIN="$target_php_pin"
  if [[ "$generation" == previous ]]; then
    export DURABLE_WORKFLOW_PHP_SDK_PIN="durable-workflow/sdk:$DURABLE_WORKFLOW_PHP_SDK_VERSION"
  fi
}

installed_packages() {
  local runtime metadata
  for runtime in php python rust; do
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
        metadata=$("${compose[@]}" run --rm --no-deps --user 1000:1000 -e POLYGLOT_RUST_MODE=sdk-metadata rust-workflow-worker) ;;
    esac
    package_json installed "$runtime" "$metadata"
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
if (( sdk_activity_upgrade )); then export POLYGLOT_TIMER_APPLICATION=v1; fi
"${compose[@]}" up -d --wait --wait-timeout 180 --no-build server timer-queue "${workers[@]}"
docker image inspect "$DURABLE_SERVER_IMAGE" --format '{{json .RepoDigests}}'

client() {
  local observer=/app/scripts/sdk_timers.py
  if (( sdk_activity_upgrade )); then observer=/app/scripts/sdk_activity_upgrades.py; fi
  "${compose[@]}" run --rm --no-deps --user 1000:1000 \
    -e DURABLE_WORKFLOW_TIMER_ID -e DURABLE_WORKFLOW_WORKER_STOPPED_AT -e DURABLE_WORKFLOW_WORKER_RESTART_AT \
    -e DURABLE_WORKFLOW_SERVER_STOPPED_AT -e DURABLE_WORKFLOW_SERVER_RESTART_AT \
    -e DURABLE_WORKFLOW_TIMER_PENDING \
    -e DURABLE_WORKFLOW_TIMER_WORKERS \
    -e DURABLE_WORKFLOW_TIMER_SDK_VERSIONS -e DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS -e DURABLE_WORKFLOW_TIMER_SDK_TARGETS \
    smoke python "$observer" "$@"
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
  if (( sdk_application_upgrade )); then
    export POLYGLOT_TIMER_APPLICATION=v1
  fi
  replace_package_workers previous "$scenario"
  start "$scenario"
  "${compose[@]}" kill --signal SIGKILL "${workers[@]}"
  export DURABLE_WORKFLOW_WORKER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  client fired "$scenario"
  export POLYGLOT_TIMER_APPLICATION=v2
  replace_package_workers current "$scenario"
  client verify "$scenario"
  start "$scenario-fresh"
  client verify "$scenario-fresh"
}

if (( sdk_activity_upgrade )); then
  remember_workers activity-upgrade
  start activity-upgrade
  "${compose[@]}" kill --signal SIGKILL "${workers[@]}"
  export DURABLE_WORKFLOW_WORKER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
  export POLYGLOT_TIMER_APPLICATION=v2
  replace_package_workers current activity-upgrade
  client verify activity-upgrade
  start activity-upgrade-fresh
  client verify activity-upgrade-fresh
elif (( sdk_packages_only )); then
  package_scenario=sdk-upgrade
  if (( sdk_application_upgrade )); then
    package_scenario=sdk-application-upgrade
  fi
  remember_workers "$package_scenario"
  package_upgrade "$package_scenario"
  package_upgrade "concurrent-$package_scenario"
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
