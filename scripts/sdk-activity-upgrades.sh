#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == --help ]]; then
  printf '%s\n' 'Usage: scripts/sdk-activity-upgrades.sh' \
    'Runs eighteen SDK/application upgrade originals across all nine PHP/Python/Rust workflow/activity directions.' \
    'Builds both package generations before admission; previous workers leave activities unclaimed, cold current workers complete the same runs.' \
    'Requires Docker Compose, Python 3 and scripts/resolve-current-artifacts.sh assignments. All project resources are removed on exit.'
  exit 0
fi
[[ $# == 0 ]] || exit 2
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export SDK_TIMERS_COMPOSE_PROJECT_NAME="${SDK_ACTIVITY_UPGRADES_COMPOSE_PROJECT_NAME:-sample-app-sdk-activity-upgrades-$(date -u +%Y%m%d%H%M%S)}"
exec "$repo_root/scripts/sdk-timers.sh" --sdk-activity-upgrades
