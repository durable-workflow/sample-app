#!/usr/bin/env bash

set -euo pipefail

run_step() {
    local label="$1"
    shift

    printf '==> %s\n' "$label"

    if "$@"; then
        return 0
    else
        local status=$?
        printf 'Codespaces setup failed during: %s\n' "$label" >&2
        printf '%s\n' 'Review the command output above and the MySQL/Redis creation logs, then rebuild the container.' >&2

        return "$status"
    fi
}

check_http_200() {
    local url="$1"
    local retries="$2"
    local retry_window="$3"
    local status

    if ! status="$(curl --fail --silent --show-error --retry "$retries" \
        --retry-all-errors --retry-delay 1 --retry-max-time "$retry_window" \
        --connect-timeout 2 --max-time 5 --output /dev/null \
        --write-out '%{http_code}' "$url")"; then
        return 1
    fi

    if [[ "$status" != 200 ]]; then
        printf 'Expected HTTP 200 from %s, received %s.\n' "$url" "$status" >&2
        return 1
    fi
}

run_step 'waiting for the Laravel container to finish starting' \
    check_http_200 http://127.0.0.1/up 120 180
run_step 'initializing Laravel, MySQL, Redis, npm, and Playwright' \
    php artisan app:init --no-interaction
run_step 'checking the Laravel health endpoint' \
    check_http_200 http://127.0.0.1/up 12 30
run_step 'checking the Laravel welcome page' \
    check_http_200 http://127.0.0.1/ 12 30

printf '%s\n' 'Codespaces setup complete: Laravel, MySQL, Redis, and Playwright are ready.'
