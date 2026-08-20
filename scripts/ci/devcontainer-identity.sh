#!/usr/bin/env bash

set -euo pipefail

wait_for_devcontainer_identity() {
    local service="$1"

    if "${compose[@]}" exec -T --user root "$service" wait-for-devcontainer-identity; then
        return 0
    fi

    "${compose[@]}" ps >&2 || true
    "${compose[@]}" logs --no-color --timestamps --tail=200 "$service" >&2 || true
    return 1
}

run_in_ready_devcontainer() {
    local service="$1"
    shift

    wait_for_devcontainer_identity "$service" || return
    "${compose[@]}" exec -T --user laravel "$service" "$@"
}
