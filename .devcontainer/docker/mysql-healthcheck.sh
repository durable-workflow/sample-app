#!/usr/bin/env bash

set -euo pipefail

healthcheck.sh --connect --innodb_initialized

# MariaDB's entrypoint disables networking on its temporary initialization
# server. A successful TCP query therefore proves every first-volume init
# script has finished and the final server is accepting application traffic.
mariadb \
    --protocol=tcp \
    --host=127.0.0.1 \
    --user="$MYSQL_USER" \
    --password="$MYSQL_PASSWORD" \
    --database="$MYSQL_DATABASE" \
    --batch \
    --skip-column-names \
    --execute='SELECT 1' \
    >/dev/null
