#!/usr/bin/env bash

set -euo pipefail

if command -v mariadb >/dev/null 2>&1; then
    database_client=mariadb
elif command -v mysql >/dev/null 2>&1; then
    database_client=mysql
else
    printf '%s\n' 'Database initialization requires the mariadb or mysql client.' >&2
    exit 1
fi

"$database_client" --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS testing;
    GRANT ALL PRIVILEGES ON \`testing%\`.* TO '$MYSQL_USER'@'%';
EOSQL
