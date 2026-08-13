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
require_anonymous_pull="${DEVCONTAINER_REQUIRE_ANONYMOUS_PULL:-0}"
skip_image_pull="${DEVCONTAINER_SKIP_IMAGE_PULL:-0}"
expected_revision="${DEVCONTAINER_EXPECTED_REVISION:-}"
evidence_type="${DEVCONTAINER_EVIDENCE_TYPE:-qualification}"
image_build_ms="${DEVCONTAINER_IMAGE_BUILD_MS:-0}"
registry="${DEVCONTAINER_REGISTRY:-local}"
runner_label="${DEVCONTAINER_RUNNER_LABEL:-unknown}"

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

normalize_architecture() {
    case "$1" in
        amd64|x86_64) echo amd64 ;;
        arm64|aarch64) echo arm64 ;;
        *)
            echo "Unsupported runner architecture: $1" >&2
            return 1
            ;;
    esac
}

expected_architecture="${platform#linux/}"
host_machine="$(uname -m)"
host_architecture="$(normalize_architecture "$host_machine")"
docker_architecture="$(normalize_architecture "$(docker info --format '{{.Architecture}}')")"

if [[ "$host_architecture" != "$expected_architecture" || "$docker_architecture" != "$expected_architecture" ]]; then
    echo "Qualification for ${platform} requires a native runner; host=${host_architecture}, docker=${docker_architecture}." >&2
    exit 1
fi

run_started_ms="${DEVCONTAINER_RUN_STARTED_MS:-$(timestamp_ms)}"
anonymous_credentials_absent=0

if [[ "$require_anonymous_pull" == "1" ]]; then
    if [[ "$skip_image_pull" == "1" ]]; then
        echo 'Anonymous pull verification cannot skip the image pull.' >&2
        exit 1
    fi

    docker_config="${DOCKER_CONFIG:-${HOME}/.docker}"
    python3 - "${docker_config}/config.json" <<'PY'
import json
import os
import sys

path = sys.argv[1]
if not os.path.exists(path):
    raise SystemExit(f"anonymous pull requires an explicit credential-free Docker config: {path}")

with open(path, encoding="utf-8") as source:
    config = json.load(source)

if config.get("auths") or config.get("credsStore") or config.get("credHelpers"):
    raise SystemExit("anonymous pull Docker config contains registry credential sources")
PY
    anonymous_credentials_absent=1
fi

project_suffix="$(printf '%s-%s' "$image" "$platform" | sha256sum | cut -c1-12)"
export COMPOSE_PROJECT_NAME="sample-app-devcontainer-${project_suffix}"
export DOCKER_DEFAULT_PLATFORM="$platform"
export SAMPLE_APP_DEVCONTAINER_IMAGE="$image"
export SAMPLE_APP_DEVCONTAINER_PULL_POLICY=never
export SAMPLE_APP_UID="$(id -u)"
export DB_DATABASE=sample
export DB_USERNAME=laravel
export DB_PASSWORD=password
export APP_PORT=18080
export MICROSERVICE_PORT=18001
export VITE_PORT=15173
export FORWARD_DB_PORT=13306
export FORWARD_REDIS_PORT=16379

compose=(docker compose --file "$compose_file")

prepare_qualification_checkout() {
    local qualification_gid

    qualification_gid="$(id -g)"
    if [[ "$(stat --format=%u "$repo_root")" != "$SAMPLE_APP_UID" \
        || "$(stat --format=%g "$repo_root")" != "$qualification_gid" ]]; then
        if command -v sudo >/dev/null 2>&1; then
            sudo chown -R "${SAMPLE_APP_UID}:${qualification_gid}" "$repo_root"
        else
            chown -R "${SAMPLE_APP_UID}:${qualification_gid}" "$repo_root"
        fi
    fi

    if [[ "$(stat --format=%u "$repo_root")" != "$SAMPLE_APP_UID" || ! -w "$repo_root" ]]; then
        echo "Qualification checkout is not writable by SAMPLE_APP_UID ${SAMPLE_APP_UID}: ${repo_root}" >&2
        exit 1
    fi
}

prepare_qualification_checkout
tracked_status_before="$(git -C "$repo_root" status --porcelain --untracked-files=no)"

verify_database_schema() {
    "${compose[@]}" exec -T mysql sh -euc '
        query_database() {
            mariadb \
                --user="$MYSQL_USER" \
                --password="$MYSQL_PASSWORD" \
                --database="$MYSQL_DATABASE" \
                --batch \
                --skip-column-names \
                --execute="$1"
        }

        query_testing_database() {
            mariadb \
                --user="$MYSQL_USER" \
                --password="$MYSQL_PASSWORD" \
                --database=testing \
                --batch \
                --skip-column-names \
                --execute="$1"
        }

        expected_migration_count=49
        expected_table_count=49
        migration_count="$(query_database "SELECT COUNT(*) FROM migrations")"
        table_count="$(query_database "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"

        if [ "$migration_count" != "$expected_migration_count" ]; then
            echo "Codespaces schema recorded ${migration_count} migrations; expected ${expected_migration_count}." >&2
            exit 1
        fi

        if [ "$table_count" != "$expected_table_count" ]; then
            echo "Codespaces schema created ${table_count} tables; expected ${expected_table_count}." >&2
            exit 1
        fi

        query_testing_database "DROP TABLE IF EXISTS codespaces_testing_probe"
        query_testing_database "CREATE TABLE codespaces_testing_probe (value VARCHAR(32) NOT NULL)"
        query_testing_database "INSERT INTO codespaces_testing_probe (value) VALUES (\"usable\")"
        [ "$(query_testing_database "SELECT value FROM codespaces_testing_probe")" = usable ]
        query_testing_database "DROP TABLE codespaces_testing_probe"
    '
}

record_database_persistence_probe() {
    "${compose[@]}" exec -T mysql sh -euc '
        mariadb \
            --user="$MYSQL_USER" \
            --password="$MYSQL_PASSWORD" \
            --database="$MYSQL_DATABASE" \
            --execute="DELETE FROM users WHERE email = \"codespaces-default-probe@example.invalid\";
                INSERT INTO users (name, email, password, created_at, updated_at)
                VALUES (\"Codespaces default probe\", \"codespaces-default-probe@example.invalid\", \"not-a-login\", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    '
}

verify_and_remove_database_persistence_probe() {
    "${compose[@]}" exec -T mysql sh -euc '
        persisted_count="$(mariadb \
            --user="$MYSQL_USER" \
            --password="$MYSQL_PASSWORD" \
            --database="$MYSQL_DATABASE" \
            --batch \
            --skip-column-names \
            --execute="SELECT COUNT(*) FROM users WHERE email = \"codespaces-default-probe@example.invalid\"")"
        [ "$persisted_count" = 1 ]
        mariadb \
            --user="$MYSQL_USER" \
            --password="$MYSQL_PASSWORD" \
            --database="$MYSQL_DATABASE" \
            --execute="DELETE FROM users WHERE email = \"codespaces-default-probe@example.invalid\""
    '
}

if [[ -n "$tracked_status_before" ]]; then
    echo 'Devcontainer qualification requires a checkout with no tracked changes.' >&2
    printf '%s\n' "$tracked_status_before" >&2
    exit 1
fi

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

seed_container_id="$("${compose[@]}" ps --all --quiet mysql-seed)"
if [[ -z "$seed_container_id" \
    || "$(docker inspect --format '{{.State.ExitCode}}' "$seed_container_id")" != 0 ]]; then
    echo 'The first-volume MySQL seed service did not complete successfully.' >&2
    exit 1
fi

"${compose[@]}" exec -T mysql test -e /var/lib/mysql/.sample-app-codespaces-seed
verify_database_schema

dependency_bootstrap_started_ms="$(timestamp_ms)"
"${compose[@]}" up --detach --no-build laravel microservice
"${compose[@]}" exec -T --user laravel laravel bash -euc '
    [[ "$(stat --format=%u .)" == "$SAMPLE_APP_UID" ]]
    [[ -w . ]]
    docker version >/dev/null
    docker compose version >/dev/null
    if [[ -e .env ]]; then
        [[ "$(stat --format=%u .env)" == "$SAMPLE_APP_UID" ]]
        [[ -w .env ]]
    fi
'
"${compose[@]}" exec -T --user laravel laravel .devcontainer/post-create.sh
"${compose[@]}" exec -T --user laravel laravel bash -euc '
    [[ -f .env ]]
    [[ "$(stat --format=%u .env)" == "$SAMPLE_APP_UID" ]]
    [[ -w .env ]]
'
"${compose[@]}" run --rm --no-deps microservice bash -euc '[[ "$(id -u)" == "$SAMPLE_APP_UID" ]]'
dependency_bootstrap_ms="$(duration_ms "$dependency_bootstrap_started_ms")"

verify_database_schema

application_readiness_started_ms="$(timestamp_ms)"
"${compose[@]}" up --detach --no-build --wait
"${compose[@]}" exec -T --user laravel laravel bash -euc '
    [[ "$(curl --silent --output /dev/null --write-out "%{http_code}" http://localhost/up)" == 200 ]]
    [[ "$(curl --silent --output /dev/null --write-out "%{http_code}" http://localhost/)" == 200 ]]
    php artisan migrate:status --no-interaction
    php artisan migrate:status --pending=1 --no-interaction
    [[ "$(redis-cli -h redis --raw ping)" == PONG ]]
'
application_readiness_ms="$(duration_ms "$application_readiness_started_ms")"

"${compose[@]}" exec -T --user laravel laravel verify-devcontainer-image
"${compose[@]}" exec -T laravel sshd -t
"${compose[@]}" exec -T --user laravel laravel node docker/playwright-smoke.js
first_app_key="$("${compose[@]}" exec -T --user laravel laravel sed -n 's/^APP_KEY=//p' .env)"
[[ "$first_app_key" == base64:* ]]
"${compose[@]}" exec -T --user laravel laravel .devcontainer/post-create.sh
second_app_key="$("${compose[@]}" exec -T --user laravel laravel sed -n 's/^APP_KEY=//p' .env)"
[[ "$second_app_key" == "$first_app_key" ]]

tracked_status_after="$(git -C "$repo_root" status --porcelain --untracked-files=no)"

if [[ -n "$tracked_status_after" ]]; then
    echo 'Codespaces setup modified tracked source files.' >&2
    printf '%s\n' "$tracked_status_after" >&2
    exit 1
fi

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
    [[ "$remote_uid" == "$SAMPLE_APP_UID" ]]
'
"${compose[@]}" exec -T --user laravel laravel bash -euc '
    [[ "$(id -u)" == "$SAMPLE_APP_UID" ]]
    composer check-platform-reqs --no-dev
    probe=.devcontainer-qualification-write-test
    printf "editable\n" > "$probe"
    [[ "$(<"$probe")" == "editable" ]]
    rm "$probe"
'

warm_rebuild_started_ms="$(timestamp_ms)"
record_database_persistence_probe
"${compose[@]}" run --rm --no-deps mysql-seed
verify_database_schema
"${compose[@]}" stop laravel microservice mysql
"${compose[@]}" up --detach --no-build --wait mysql redis
"${compose[@]}" up --detach --no-build --force-recreate --wait laravel microservice
"${compose[@]}" exec -T --user laravel laravel curl --fail --silent http://localhost/up >/dev/null
verify_and_remove_database_persistence_probe
"${compose[@]}" exec -T --user laravel laravel bash -euc '
    migration_name=create_codespaces_future_migration_probe_table
    php artisan make:migration "$migration_name" \
        --create=codespaces_future_migration_probe \
        --no-interaction
    migration_paths=(database/migrations/*_"${migration_name}".php)
    if (( ${#migration_paths[@]} != 1 )); then
        echo "Expected one future-migration probe, found ${#migration_paths[@]}." >&2
        exit 1
    fi
    migration_path="${migration_paths[0]}"
    trap '\''rm -f "$migration_path"'\'' EXIT
    php artisan migrate --force --no-interaction
    php artisan migrate:rollback \
        --force \
        --no-interaction \
        --path="$migration_path"
'
verify_database_schema
warm_rebuild_ms="$(duration_ms "$warm_rebuild_started_ms")"

checkout_status_after="$(git -C "$repo_root" status --porcelain)"
if [[ -n "$checkout_status_after" ]]; then
    echo 'Devcontainer qualification left the checkout dirty after persistent-volume validation.' >&2
    printf '%s\n' "$checkout_status_after" >&2
    exit 1
fi

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

database_override_started_ms="$(timestamp_ms)"
"${repo_root}/scripts/ci/qualify-devcontainer-database-overrides.sh"
database_override_ms="$(duration_ms "$database_override_started_ms")"

mkdir -p "$(dirname "$timing_output")"
IMAGE="$image" \
PLATFORM="$platform" \
REVISION="$revision_label" \
ANONYMOUS_CREDENTIALS_ABSENT="$anonymous_credentials_absent" \
COMPLETED_MS="$(timestamp_ms)" \
DOCKER_ARCHITECTURE="$docker_architecture" \
EVIDENCE_TYPE="$evidence_type" \
HOST_ARCHITECTURE="$host_architecture" \
HOST_MACHINE="$host_machine" \
IMAGE_PULL_MS="$image_pull_ms" \
IMAGE_BUILD_MS="$image_build_ms" \
CONTAINER_READINESS_MS="$container_readiness_ms" \
DEPENDENCY_BOOTSTRAP_MS="$dependency_bootstrap_ms" \
APPLICATION_READINESS_MS="$application_readiness_ms" \
DATABASE_OVERRIDE_MS="$database_override_ms" \
FRESH_TOTAL_MS="$fresh_total_ms" \
REGISTRY="$registry" \
REQUIRE_ANONYMOUS_PULL="$require_anonymous_pull" \
RUNNER_LABEL="$runner_label" \
RUN_STARTED_MS="$run_started_ms" \
WARM_REBUILD_MS="$warm_rebuild_ms" \
TIMING_OUTPUT="$timing_output" \
python3 <<'PY'
import json
import os

payload = {
    "schema_version": 2,
    "evidence_type": os.environ["EVIDENCE_TYPE"],
    "image": os.environ["IMAGE"],
    "platform": os.environ["PLATFORM"],
    "registry": os.environ["REGISTRY"],
    "source_revision": os.environ["REVISION"],
    "runner": {
        "label": os.environ["RUNNER_LABEL"],
        "host_machine": os.environ["HOST_MACHINE"],
        "host_architecture": os.environ["HOST_ARCHITECTURE"],
        "docker_architecture": os.environ["DOCKER_ARCHITECTURE"],
    },
    "anonymous_pull_verification": {
        "required": os.environ["REQUIRE_ANONYMOUS_PULL"] == "1",
        "credentials_absent": os.environ["ANONYMOUS_CREDENTIALS_ABSENT"] == "1",
        "pull_performed": os.environ["REQUIRE_ANONYMOUS_PULL"] == "1",
    },
    "environment_builds": 0,
    "phases_ms": {
        "image_pull": int(os.environ["IMAGE_PULL_MS"]),
        "container_readiness": int(os.environ["CONTAINER_READINESS_MS"]),
        "dependency_bootstrap": int(os.environ["DEPENDENCY_BOOTSTRAP_MS"]),
        "application_readiness": int(os.environ["APPLICATION_READINESS_MS"]),
    },
    "stages_ms": {
        "image_build": int(os.environ["IMAGE_BUILD_MS"]),
        "image_pull": int(os.environ["IMAGE_PULL_MS"]),
        "container_readiness": int(os.environ["CONTAINER_READINESS_MS"]),
        "dependency_bootstrap": int(os.environ["DEPENDENCY_BOOTSTRAP_MS"]),
        "application_readiness": int(os.environ["APPLICATION_READINESS_MS"]),
        "database_override": int(os.environ["DATABASE_OVERRIDE_MS"]),
        "warm_rebuild": int(os.environ["WARM_REBUILD_MS"]),
    },
    "fresh_total_ms": int(os.environ["FRESH_TOTAL_MS"]),
    "warm_rebuild_ms": int(os.environ["WARM_REBUILD_MS"]),
    "run_started_epoch_ms": int(os.environ["RUN_STARTED_MS"]),
    "completed_epoch_ms": int(os.environ["COMPLETED_MS"]),
}

with open(os.environ["TIMING_OUTPUT"], "w", encoding="utf-8") as output:
    json.dump(payload, output, indent=2, sort_keys=True)
    output.write("\n")
PY

cat "$timing_output"
