FROM node:22-bookworm-slim AS node

FROM composer:2 AS composer

FROM docker:27.5.1-cli AS docker-cli

FROM rust:1.86.0-slim-bookworm AS rust

RUN set -eux; \
    rm -rf /usr/local/rustup/downloads /usr/local/rustup/tmp; \
    find /usr/local/rustup/toolchains -type d \
        \( -path '*/share/doc' -o -path '*/share/man' \) \
        -prune -exec rm -rf '{}' +; \
    rustc --version; \
    cargo --version

FROM mariadb:11.4 AS mysql-seed

ENV MYSQL_ROOT_PASSWORD=password \
    MYSQL_ROOT_HOST=% \
    MYSQL_DATABASE=sample \
    MYSQL_USER=laravel \
    MYSQL_PASSWORD=password

COPY .devcontainer/docker/create-testing-database.sh /docker-entrypoint-initdb.d/10-create-testing-database.sh
COPY .devcontainer/schema/mysql-schema.sql /docker-entrypoint-initdb.d/20-sample-app-schema.sql

RUN set -eux; \
    docker-entrypoint.sh mariadbd \
        --innodb-flush-method=nosync \
        --innodb-flush-log-at-trx-commit=0 \
        --innodb-doublewrite=OFF \
        --innodb-file-per-table=OFF \
        --innodb-buffer-pool-size=64M \
        --innodb-log-file-size=16M \
        --performance-schema=OFF \
        --skip-name-resolve & \
    database_pid="$!"; \
    database_ready=0; \
    for attempt in $(seq 1 180); do \
        if healthcheck.sh --connect --innodb_initialized \
            && mariadb \
                --protocol=tcp \
                --host=127.0.0.1 \
                --user="$MYSQL_USER" \
                --password="$MYSQL_PASSWORD" \
                --database="$MYSQL_DATABASE" \
                --batch \
                --skip-column-names \
                --execute='SELECT 1' \
                >/dev/null; then \
            database_ready=1; \
            break; \
        fi; \
        sleep 1; \
    done; \
    if [ "$database_ready" != 1 ]; then \
        echo 'Timed out while creating the Codespaces MySQL seed.' >&2; \
        exit 1; \
    fi; \
    migration_count="$(mariadb \
        --user="$MYSQL_USER" \
        --password="$MYSQL_PASSWORD" \
        --database="$MYSQL_DATABASE" \
        --batch \
        --skip-column-names \
        --execute='SELECT COUNT(*) FROM migrations')"; \
    test "$migration_count" = 49; \
    mariadb-admin \
        --user=root \
        --password="$MYSQL_ROOT_PASSWORD" \
        --protocol=socket \
        shutdown; \
    wait "$database_pid"; \
    rm -f /var/lib/mysql/auto.cnf; \
    touch /var/lib/mysql/.sample-app-codespaces-seed; \
    tar \
        --create \
        --file=/tmp/sample-app-mysql-seed.tar \
        --format=posix \
        --numeric-owner \
        --sort=name \
        --directory=/var/lib/mysql \
        .; \
    test -s /tmp/sample-app-mysql-seed.tar

FROM php:8.4-cli-bookworm

ARG VCS_REF=unknown
ARG DURABLE_WORKFLOW_CLI_VERSION=2.0.0-rc.35

LABEL org.opencontainers.image.title="Durable Workflow Sample App development container" \
    org.opencontainers.image.description="PHP, Python, Rust, Docker, dw, Node, Composer, and Chromium environment for the Durable Workflow Sample App" \
    org.opencontainers.image.source="https://github.com/durable-workflow/sample-app" \
    org.opencontainers.image.revision="${VCS_REF}" \
    org.opencontainers.image.licenses="MIT"

ENV DEBIAN_FRONTEND=noninteractive \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    TZ=UTC \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    VIRTUAL_ENV=/opt/sample-app-playground/python \
    RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/home/laravel/.cargo \
    CARGO_TARGET_DIR=/opt/sample-app-playground/cargo-target \
    CARGO_INCREMENTAL=0 \
    CARGO_PROFILE_DEV_DEBUG=0 \
    DURABLE_WORKFLOW_CLI_VERSION=${DURABLE_WORKFLOW_CLI_VERSION} \
    PATH=/opt/sample-app-playground/python/bin:/usr/local/cargo/bin:${PATH}

WORKDIR /var/www/html

COPY --from=node /usr/local/ /usr/local/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
COPY docker/install-phpredis.sh /usr/local/bin/install-phpredis

RUN set -eux; \
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime; \
    echo "${TZ}" > /etc/timezone; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-mysql-client \
        ffmpeg \
        git \
        gosu \
        libcap2-bin \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libzip-dev \
        openssh-server \
        python3 \
        python3-pip \
        python3-venv \
        redis-tools \
        ripgrep \
        sqlite3 \
        supervisor \
        unzip \
        zip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip; \
    sh /usr/local/bin/install-phpredis; \
    extension_libraries=/tmp/php-extension-libraries; \
    runtime_packages=/tmp/php-runtime-packages; \
    find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { print $(NF - 1) }' \
        | sort -u > "$extension_libraries"; \
    : > "$runtime_packages"; \
    while IFS= read -r library; do \
        canonical_library="$(readlink -f "$library")"; \
        if [ -z "$canonical_library" ] || [ ! -e "$canonical_library" ]; then \
            echo "Unable to resolve PHP extension dependency: ${library}" >&2; \
            exit 1; \
        fi; \
        if package_matches="$(dpkg-query --search "$canonical_library" 2>/dev/null)"; then \
            :; \
        elif package_matches="$(dpkg-query --search "$library" 2>/dev/null)"; then \
            :; \
        else \
            echo "No Debian runtime package owns PHP extension dependency: ${library} (canonical: ${canonical_library})" >&2; \
            exit 1; \
        fi; \
        printf '%s\n' "$package_matches" | cut -d: -f1 >> "$runtime_packages"; \
    done < "$extension_libraries"; \
    sort -u "$runtime_packages" -o "$runtime_packages"; \
    test -s "$runtime_packages"; \
    xargs -r apt-mark manual < "$runtime_packages"; \
    apt-get purge -y --auto-remove \
        ${PHPIZE_DEPS} \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libzip-dev; \
    for extension in bcmath curl gd intl mbstring pcntl pdo_mysql pdo_sqlite redis zip; do \
        if ! php --ri "$extension" >/dev/null; then \
            echo "Required PHP extension is unavailable after build dependency cleanup: ${extension}" >&2; \
            exit 1; \
        fi; \
    done; \
    apt-get install -y --no-install-recommends build-essential pkg-config; \
    groupadd --gid 1000 laravel; \
    useradd --create-home --gid laravel --shell /bin/bash --uid 1000 laravel; \
    passwd --delete laravel; \
    install -d -m 0775 -o laravel -g laravel /home/laravel/.cargo /home/laravel/.composer /ms-playwright /var/www/html; \
    ln -s /usr/bin/python3 /usr/local/bin/python; \
    ln -s /usr/bin/pip3 /usr/local/bin/pip; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /var/tmp/*; \
    setcap 'cap_net_bind_service=+ep' "$(command -v php)"; \
    getcap "$(command -v php)" | grep -F 'cap_net_bind_service=ep'; \
    install -d -m 0700 -o laravel -g laravel /home/laravel/.ssh; \
    install -d -m 0755 /run/sshd; \
    printf '%s\n' \
        'KbdInteractiveAuthentication no' \
        'PasswordAuthentication no' \
        'PermitEmptyPasswords no' \
        'PermitRootLogin no' \
        'PubkeyAuthentication yes' \
        > /etc/ssh/sshd_config.d/99-sample-app.conf; \
    ssh-keygen -A; \
    sshd -t; \
    rm -f /etc/ssh/ssh_host_*_key /etc/ssh/ssh_host_*_key.pub

RUN set -eux; \
    curl -fsSL https://durable-workflow.com/install.sh -o /tmp/dw-install.sh; \
    VERSION="$DURABLE_WORKFLOW_CLI_VERSION" \
        DURABLE_WORKFLOW_INSTALL_DIR=/usr/local/bin \
        sh /tmp/dw-install.sh; \
    rm /tmp/dw-install.sh; \
    dw --version

COPY playground/templates/python/requirements.txt /tmp/sample-app-python-playground-requirements.txt
COPY polyglot/qualified-artifact-tuple.json /tmp/sample-app-qualified-artifact-tuple.json

RUN set -eux; \
    python_sdk_version="$(php -r '$tuple = json_decode(file_get_contents("/tmp/sample-app-qualified-artifact-tuple.json"), true, flags: JSON_THROW_ON_ERROR); echo $tuple["artifacts"]["sdk-python"];')"; \
    python_distribution_version="$(printf '%s' "$python_sdk_version" | sed 's/-rc\./rc/')"; \
    python3 -m venv "$VIRTUAL_ENV"; \
    "$VIRTUAL_ENV/bin/pip" install \
        --no-input \
        --no-cache-dir \
        --disable-pip-version-check \
        --requirement /tmp/sample-app-python-playground-requirements.txt \
        "durable-workflow==${python_distribution_version}"; \
    EXPECTED_PYTHON_SDK="$python_distribution_version" \
        "$VIRTUAL_ENV/bin/python" -c 'import durable_workflow, importlib.metadata as metadata, os; assert metadata.version("durable-workflow") == os.environ["EXPECTED_PYTHON_SDK"]'; \
    rm /tmp/sample-app-python-playground-requirements.txt /tmp/sample-app-qualified-artifact-tuple.json; \
    chown -R laravel:laravel /opt/sample-app-playground; \
    chmod -R g=u /opt/sample-app-playground

COPY --chown=laravel:laravel playground/php-runtime/composer.json playground/php-runtime/composer.lock /opt/sample-app-playground/php/

RUN set -eux; \
    gosu laravel env COMPOSER_HOME=/home/laravel/.composer composer install \
        --working-dir=/opt/sample-app-playground/php \
        --prefer-dist \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-plugins \
        --no-scripts; \
    gosu laravel test -s /opt/sample-app-playground/php/vendor/durable-workflow/sdk/examples/bootstrap.php; \
    gosu laravel test -s /opt/sample-app-playground/php/vendor/durable-workflow/sdk/docs/quickstart-contract.json; \
    chmod -R g=u /opt/sample-app-playground/php

COPY --chown=laravel:laravel playground/templates/rust /tmp/sample-app-rust-playground

RUN set -eux; \
    install -d -m 0775 -o laravel -g laravel "$CARGO_HOME" "$CARGO_TARGET_DIR"; \
    gosu laravel cargo build \
        --locked \
        --manifest-path=/tmp/sample-app-rust-playground/Cargo.toml \
        --bins; \
    rm -rf "$CARGO_TARGET_DIR/debug/incremental" /tmp/sample-app-rust-playground; \
    gosu laravel test -d "$CARGO_HOME/registry/cache"; \
    gosu laravel test -d "$CARGO_TARGET_DIR/debug/deps"; \
    chmod -R g=u "$CARGO_HOME" "$CARGO_TARGET_DIR"

COPY package-lock.json /tmp/sample-app-package-lock.json

RUN set -eux; \
    playwright_version="$(node -e 'const lock = require("/tmp/sample-app-package-lock.json"); process.stdout.write(lock.packages["node_modules/playwright"].version)')"; \
    test -n "$playwright_version"; \
    npm install --global --no-audit --no-fund "playwright@${playwright_version}"; \
    playwright install --with-deps --only-shell chromium; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /var/tmp/*

COPY --chown=laravel:laravel composer.json composer.lock /var/www/html/
COPY --chown=laravel:laravel microservice/composer.json microservice/composer.lock /var/www/html/microservice/

RUN set -eux; \
    for dependency_dir in /var/www/html /var/www/html/microservice; do \
        install -d -m 0775 -o laravel -g laravel "${dependency_dir}/vendor"; \
        for writable_path in "$dependency_dir" "${dependency_dir}/vendor"; do \
            if ! gosu laravel test -w "$writable_path"; then \
                echo "Composer seed path is not writable by laravel: ${writable_path}" >&2; \
                exit 1; \
            fi; \
        done; \
        COMPOSER_HOME=/home/laravel/.composer gosu laravel composer validate \
            --working-dir="$dependency_dir" \
            --strict \
            --check-lock \
            --no-check-all \
            --no-interaction; \
        COMPOSER_HOME=/home/laravel/.composer gosu laravel composer install \
            --working-dir="$dependency_dir" \
            --prefer-dist \
            --no-interaction \
            --no-plugins \
            --no-progress \
            --no-scripts; \
        gosu laravel test -s "${dependency_dir}/vendor/autoload.php"; \
        chmod -R g=u "${dependency_dir}/vendor"; \
    done; \
    rm -rf /home/laravel/.composer/cache; \
    if test -e /home/laravel/.composer/auth.json; then \
        echo 'Composer credentials must not be baked into the development image.' >&2; \
        exit 1; \
    fi; \
    chmod -R g=u /home/laravel/.composer; \
    rm -rf /tmp/* /var/tmp/*

ENV COMPOSER_HOME=/home/laravel/.composer

COPY --from=mysql-seed /tmp/sample-app-mysql-seed.tar /usr/local/share/sample-app/mysql-datadir.tar
COPY .devcontainer/docker/start-container /usr/local/bin/start-container
COPY .devcontainer/docker/wait-for-identity-ready /usr/local/bin/wait-for-devcontainer-identity
COPY .devcontainer/docker/seed-mysql-volume /usr/local/bin/seed-mysql-volume
COPY .devcontainer/docker/verify-prepared-permissions /usr/local/bin/verify-prepared-permissions
COPY .devcontainer/docker/verify-image.sh /usr/local/bin/verify-devcontainer-image
COPY .devcontainer/docker/with-disposable-composer-state /usr/local/bin/with-disposable-composer-state
COPY .devcontainer/docker/with-group-shared-umask /usr/local/bin/with-group-shared-umask
COPY .devcontainer/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .devcontainer/docker/php.ini /usr/local/etc/php/conf.d/99-laravel.ini

RUN chmod +x /usr/local/bin/seed-mysql-volume /usr/local/bin/start-container \
        /usr/local/bin/verify-devcontainer-image /usr/local/bin/verify-prepared-permissions \
        /usr/local/bin/with-disposable-composer-state \
        /usr/local/bin/with-group-shared-umask \
        /usr/local/bin/wait-for-devcontainer-identity \
    && DEVCONTAINER_SSH_HOST_KEY_STATE=absent gosu laravel verify-devcontainer-image

EXPOSE 22 80 5173

ENTRYPOINT ["start-container"]
