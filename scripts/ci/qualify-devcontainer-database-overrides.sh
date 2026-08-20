#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="${repo_root}/.devcontainer/docker/docker-compose.yml"
compose=(docker compose --file "$compose_file")
source "${repo_root}/scripts/ci/devcontainer-identity.sh"

export DB_DATABASE=codespaces_override
export DB_USERNAME=codespaces_user
export DB_PASSWORD=codespaces_password

verify_database_contract() {
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

        [ "$MYSQL_DATABASE" = codespaces_override ]
        [ "$MYSQL_USER" = codespaces_user ]
        [ "$MYSQL_PASSWORD" = codespaces_password ]
        [ "$(query_database "SELECT COUNT(*) FROM migrations")" = 49 ]
        [ "$(query_database "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")" = 49 ]

        query_testing_database "DROP TABLE IF EXISTS codespaces_testing_probe"
        query_testing_database "CREATE TABLE codespaces_testing_probe (value VARCHAR(32) NOT NULL)"
        query_testing_database "INSERT INTO codespaces_testing_probe (value) VALUES (\"usable\")"
        [ "$(query_testing_database "SELECT value FROM codespaces_testing_probe")" = usable ]
        query_testing_database "DROP TABLE codespaces_testing_probe"
    '
}

verify_application_contract() {
    run_in_ready_devcontainer laravel bash -euc '
        grep -Fx "DB_DATABASE=$DB_DATABASE" .env
        grep -Fx "DB_USERNAME=$DB_USERNAME" .env
        grep -Fx "DB_PASSWORD=$DB_PASSWORD" .env
        grep -Fx "SHARED_DB_DATABASE=$SHARED_DB_DATABASE" .env
        grep -Fx "SHARED_DB_USERNAME=$SHARED_DB_USERNAME" .env
        grep -Fx "SHARED_DB_PASSWORD=$SHARED_DB_PASSWORD" .env
        php artisan migrate:status --no-interaction
        php artisan migrate:status --pending=1 --no-interaction
        curl --fail --silent http://localhost/up >/dev/null
        curl --fail --silent http://localhost/ >/dev/null
    '

    "${compose[@]}" exec -T --user laravel microservice php artisan tinker --execute='
        $database = DB::connection("shared")->selectOne("SELECT DATABASE() AS name")->name;
        throw_unless($database === getenv("SHARED_DB_DATABASE"), "Shared database override was not applied.");
    '
}

bootstrap_devcontainer_application() {
    "${compose[@]}" up --detach --no-build laravel microservice
    run_in_ready_devcontainer laravel .devcontainer/post-create.sh
    "${compose[@]}" up --detach --no-build --wait
}

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
if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then
    return 0
fi

trap cleanup EXIT

"${compose[@]}" down --volumes --remove-orphans
"${compose[@]}" up --detach --no-build --wait mysql redis

seed_container_id="$("${compose[@]}" ps --all --quiet mysql-seed)"
if [[ -z "$seed_container_id" \
    || "$(docker inspect --format '{{.State.ExitCode}}' "$seed_container_id")" != 0 ]]; then
    echo 'The database-override seed guard did not complete successfully.' >&2
    exit 1
fi

"${compose[@]}" exec -T mysql test ! -e /var/lib/mysql/.sample-app-codespaces-seed
verify_database_contract

bootstrap_devcontainer_application
verify_application_contract

"${compose[@]}" exec -T mysql sh -euc '
    mariadb \
        --user="$MYSQL_USER" \
        --password="$MYSQL_PASSWORD" \
        --database="$MYSQL_DATABASE" \
        --execute="DELETE FROM users WHERE email = \"codespaces-override-probe@example.invalid\";
            INSERT INTO users (name, email, password, created_at, updated_at)
            VALUES (\"Codespaces override probe\", \"codespaces-override-probe@example.invalid\", \"not-a-login\", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
'

"${compose[@]}" run --rm --no-deps mysql-seed
"${compose[@]}" stop laravel microservice mysql
"${compose[@]}" up --detach --no-build --wait mysql redis
"${compose[@]}" up --detach --no-build --force-recreate --wait laravel microservice

"${compose[@]}" exec -T mysql sh -euc '
    persisted_count="$(mariadb \
        --user="$MYSQL_USER" \
        --password="$MYSQL_PASSWORD" \
        --database="$MYSQL_DATABASE" \
        --batch \
        --skip-column-names \
        --execute="SELECT COUNT(*) FROM users WHERE email = \"codespaces-override-probe@example.invalid\"")"
    [ "$persisted_count" = 1 ]
    mariadb \
        --user="$MYSQL_USER" \
        --password="$MYSQL_PASSWORD" \
        --database="$MYSQL_DATABASE" \
        --execute="DELETE FROM users WHERE email = \"codespaces-override-probe@example.invalid\""
'

verify_database_contract
verify_application_contract

checkout_status="$(git -C "$repo_root" status --porcelain)"
if [[ -n "$checkout_status" ]]; then
    echo 'Database-override qualification left the checkout dirty.' >&2
    printf '%s\n' "$checkout_status" >&2
    exit 1
fi
