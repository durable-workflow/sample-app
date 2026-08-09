#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="${repo_root}/.devcontainer/docker/docker-compose.yml"
image="${1:?Usage: qualify-devcontainer-image.sh IMAGE PLATFORM [TIMING_OUTPUT]}"
platform="${2:?Usage: qualify-devcontainer-image.sh IMAGE PLATFORM [TIMING_OUTPUT]}"
timing_output="${3:-${repo_root}/devcontainer-qualification-timing.json}"
max_fresh_seconds="${DEVCONTAINER_MAX_FRESH_SECONDS:-300}"
max_warm_seconds="${DEVCONTAINER_MAX_WARM_SECONDS:-120}"
require_attestations="${DEVCONTAINER_REQUIRE_PUBLISHED_ATTESTATIONS:-1}"
skip_image_pull="${DEVCONTAINER_SKIP_IMAGE_PULL:-0}"
expected_revision="${DEVCONTAINER_EXPECTED_REVISION:-}"

case "$platform" in
    linux/amd64|linux/arm64) ;;
    *)
        echo "Unsupported qualification platform: ${platform}" >&2
        exit 2
        ;;
esac

timestamp_ms() {
    date +%s%3N
}

duration_ms() {
    local started_ms="$1"
    echo $(( $(timestamp_ms) - started_ms ))
}

project_suffix="$(printf '%s-%s' "$image" "$platform" | sha256sum | cut -c1-12)"
export COMPOSE_PROJECT_NAME="sample-app-devcontainer-${project_suffix}"
export DOCKER_DEFAULT_PLATFORM="$platform"
export SAMPLE_APP_DEVCONTAINER_IMAGE="$image"
export SAMPLE_APP_DEVCONTAINER_PULL_POLICY=never
export APP_PORT=18080
export MICROSERVICE_PORT=18001
export VITE_PORT=15173
export FORWARD_DB_PORT=13306
export FORWARD_REDIS_PORT=16379

compose=(docker compose --file "$compose_file")

cleanup() {
    local status=$?
    trap - EXIT

    if (( status != 0 )); then
        "${compose[@]}" ps >&2 || true
        "${compose[@]}" logs --no-color --timestamps --tail=200 >&2 || true
    fi

    "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT

image_pull_started_ms="$(timestamp_ms)"
if [[ "$skip_image_pull" == "1" ]]; then
    docker image inspect "$image" >/dev/null
else
    docker pull --platform "$platform" "$image"
fi
image_pull_ms="$(duration_ms "$image_pull_started_ms")"

if [[ "$require_attestations" == "1" ]]; then
    manifest_path="$(mktemp)"
    docker buildx imagetools inspect "$image" --raw > "$manifest_path"
    python3 - "$manifest_path" <<'PY'
import json
import sys

manifest = json.load(open(sys.argv[1], encoding="utf-8"))
entries = manifest.get("manifests", [])
architectures = {
    entry.get("platform", {}).get("architecture")
    for entry in entries
    if entry.get("platform", {}).get("architecture") not in (None, "unknown")
}
missing = {"amd64", "arm64"} - architectures
if missing:
    raise SystemExit(f"published image is missing platforms: {sorted(missing)}")

attestations = [
    entry
    for entry in entries
    if entry.get("annotations", {}).get("vnd.docker.reference.type")
    == "attestation-manifest"
]
if len(attestations) < 2:
    raise SystemExit("published image does not expose per-platform attestations")
PY
    rm -f "$manifest_path"

    provenance="$(docker buildx imagetools inspect "$image" --format '{{ json .Provenance }}')"
    sbom="$(docker buildx imagetools inspect "$image" --format '{{ json .SBOM }}')"
    [[ -n "$provenance" && "$provenance" != "null" ]]
    [[ -n "$sbom" && "$sbom" != "null" ]]
fi

source_label="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.source" }}' "$image")"
revision_label="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image")"
[[ "$source_label" == "https://github.com/durable-workflow/sample-app" ]]

if [[ -n "$expected_revision" ]]; then
    [[ "$revision_label" == "$expected_revision" ]]
fi

container_readiness_started_ms="$(timestamp_ms)"
"${compose[@]}" pull mysql redis
"${compose[@]}" up --detach --no-build --wait mysql redis
container_readiness_ms="$(duration_ms "$container_readiness_started_ms")"

dependency_bootstrap_started_ms="$(timestamp_ms)"
"${compose[@]}" run --rm --no-deps laravel php artisan app:init
"${compose[@]}" run --rm --no-deps microservice true
dependency_bootstrap_ms="$(duration_ms "$dependency_bootstrap_started_ms")"

application_readiness_started_ms="$(timestamp_ms)"
"${compose[@]}" up --detach --no-build --wait
"${compose[@]}" exec -T --user laravel laravel curl --fail --silent http://localhost/up >/dev/null
application_readiness_ms="$(duration_ms "$application_readiness_started_ms")"

"${compose[@]}" exec -T --user laravel laravel verify-devcontainer-image
"${compose[@]}" exec -T laravel sshd -t
"${compose[@]}" exec -T --user laravel laravel node docker/playwright-smoke.js
"${compose[@]}" exec -T laravel bash -euc '
    exec 3<>/dev/tcp/127.0.0.1/22
    IFS= read -r -t 5 ssh_banner <&3
    exec 3<&-
    exec 3>&-
    [[ "$ssh_banner" == SSH-* ]]

    key_dir="$(mktemp -d)"
    trap "rm -rf \"$key_dir\"" EXIT
    ssh-keygen -q -t ed25519 -N "" -f "$key_dir/id_ed25519"
    install -m 0600 -o laravel -g laravel \
        "$key_dir/id_ed25519.pub" /home/laravel/.ssh/authorized_keys
    chown -R laravel:laravel "$key_dir"
    remote_uid="$(gosu laravel ssh \
        -o BatchMode=yes \
        -o ConnectTimeout=5 \
        -o LogLevel=ERROR \
        -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -i "$key_dir/id_ed25519" \
        laravel@127.0.0.1 id -u)"
    [[ "$remote_uid" != "0" ]]
'
"${compose[@]}" exec -T --user laravel laravel bash -euc '
    [[ "$(id -u)" != "0" ]]
    composer check-platform-reqs --no-dev
    probe=.devcontainer-qualification-write-test
    printf "editable\n" > "$probe"
    [[ "$(<"$probe")" == "editable" ]]
    rm "$probe"
'

warm_rebuild_started_ms="$(timestamp_ms)"
"${compose[@]}" up --detach --no-build --force-recreate --wait laravel microservice
"${compose[@]}" exec -T --user laravel laravel curl --fail --silent http://localhost/up >/dev/null
warm_rebuild_ms="$(duration_ms "$warm_rebuild_started_ms")"

fresh_total_ms=$(( image_pull_ms + container_readiness_ms + dependency_bootstrap_ms + application_readiness_ms ))
max_fresh_ms=$(( max_fresh_seconds * 1000 ))
max_warm_ms=$(( max_warm_seconds * 1000 ))

if (( fresh_total_ms >= max_fresh_ms )); then
    echo "Fresh devcontainer qualification took ${fresh_total_ms}ms; limit is ${max_fresh_ms}ms." >&2
    exit 1
fi

if (( warm_rebuild_ms >= max_warm_ms )); then
    echo "Warm devcontainer rebuild took ${warm_rebuild_ms}ms; limit is ${max_warm_ms}ms." >&2
    exit 1
fi

mkdir -p "$(dirname "$timing_output")"
IMAGE="$image" \
PLATFORM="$platform" \
REVISION="$revision_label" \
IMAGE_PULL_MS="$image_pull_ms" \
CONTAINER_READINESS_MS="$container_readiness_ms" \
DEPENDENCY_BOOTSTRAP_MS="$dependency_bootstrap_ms" \
APPLICATION_READINESS_MS="$application_readiness_ms" \
FRESH_TOTAL_MS="$fresh_total_ms" \
WARM_REBUILD_MS="$warm_rebuild_ms" \
TIMING_OUTPUT="$timing_output" \
python3 <<'PY'
import json
import os

payload = {
    "schema_version": 1,
    "image": os.environ["IMAGE"],
    "platform": os.environ["PLATFORM"],
    "source_revision": os.environ["REVISION"],
    "environment_builds": 0,
    "phases_ms": {
        "image_pull": int(os.environ["IMAGE_PULL_MS"]),
        "container_readiness": int(os.environ["CONTAINER_READINESS_MS"]),
        "dependency_bootstrap": int(os.environ["DEPENDENCY_BOOTSTRAP_MS"]),
        "application_readiness": int(os.environ["APPLICATION_READINESS_MS"]),
    },
    "fresh_total_ms": int(os.environ["FRESH_TOTAL_MS"]),
    "warm_rebuild_ms": int(os.environ["WARM_REBUILD_MS"]),
}

with open(os.environ["TIMING_OUTPUT"], "w", encoding="utf-8") as output:
    json.dump(payload, output, indent=2, sort_keys=True)
    output.write("\n")
PY

cat "$timing_output"
