#!/usr/bin/env bash

set -euo pipefail

/usr/local/bin/wait-for-devcontainer-identity

identity_readiness_marker=/run/sample-app/identity-ready
expected_uid="$(sed -n 's/^uid=//p' "$identity_readiness_marker")"
socket_gid="$(stat --format=%g /var/run/docker.sock)"

if [[ "$(id -u)" != "$expected_uid" ]]; then
    echo "Post-create started with UID $(id -u) before the prepared UID ${expected_uid} was available." >&2
    exit 1
fi

if [[ " $(id -G) " != *" ${socket_gid} "* ]]; then
    socket_group="$(awk -F: -v gid="$socket_gid" '$3 == gid { print $1; exit }' /etc/group)"
    if [[ -z "$socket_group" ]]; then
        echo "Docker socket group ${socket_gid} is unavailable after identity preparation." >&2
        exit 1
    fi

    post_create_path="$(realpath "${BASH_SOURCE[0]}")"
    printf -v quoted_post_create_path '%q' "$post_create_path"
    exec sg "$socket_group" -c "exec ${quoted_post_create_path}"
fi

timestamp_ms() {
    date +%s%3N
}

run_step() {
    local label="$1"
    local started_ms
    shift

    started_ms="$(timestamp_ms)"
    printf '==> %s\n' "$label"

    if "$@"; then
        printf '<== %s (%sms)\n' "$label" "$(( $(timestamp_ms) - started_ms ))"
        return 0
    else
        local status=$?
        printf 'Codespaces setup failed during: %s\n' "$label" >&2
        printf '%s\n' 'Review the command output above and the MySQL/Redis creation logs, then rebuild the container.' >&2

        return "$status"
    fi
}

setup_started_ms="$(timestamp_ms)"

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
    php artisan app:init \
        --schema-path=.devcontainer/schema/mysql-schema.sql \
        --no-interaction
run_step 'checking the Laravel health endpoint' \
    check_http_200 http://127.0.0.1/up 12 30
run_step 'checking the Laravel welcome page' \
    check_http_200 http://127.0.0.1/ 12 30
run_step 'checking PHP, Python, Rust, Docker Compose, and dw playground tools' \
    scripts/playground doctor

printf 'Codespaces setup complete: Laravel and the PHP, Python, and Rust playgrounds are ready (%sms total).\n' \
    "$(( $(timestamp_ms) - setup_started_ms ))"
