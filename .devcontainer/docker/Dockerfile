FROM node:22.23.4-bookworm-slim@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS node

FROM composer:2.11.0@sha256:278dfc7bd35169965d03f87194816f43bf4e6b6ea61707c2114e52ad12fe24ce AS composer

FROM php:8.4.27-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76 AS brotli-build

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY .devcontainer/docker/verify-php-trust.php /usr/local/share/sample-app/verify-php-trust.php
COPY docker/brotli/version docker/brotli/install-vendor.sh docker/brotli/verify-runtime.php docker/brotli/verify-system-brotli.c /usr/src/brotli-inputs/
RUN php /usr/local/share/sample-app/verify-php-trust.php /usr/local/bin/composer \
    && sh /usr/src/brotli-inputs/install-vendor.sh /usr/src/brotli-inputs gcc libc6-dev \
    && mkdir -p /out \
    && cc -O2 /usr/src/brotli-inputs/verify-system-brotli.c -lbrotlidec -lbrotlienc -ldl -o /out/verify-system-brotli \
    && /out/verify-system-brotli \
    && cp /usr/src/brotli-inputs/version /usr/src/brotli-inputs/install-vendor.sh /usr/src/brotli-inputs/verify-runtime.php /out/

FROM docker:29.9.2-cli@sha256:5e6baf67325a79f64ed3bed6863954d5e05d0f3d4e57cbdb14809346814bbfdb AS docker-cli

FROM golang:1.27.3-bookworm@sha256:d9b6a1beb9474adc2a1ce842573639b44b2a0dd49b6669f51d3821baf9ec37c9 AS go-tools

ENV CGO_ENABLED=0 GOTOOLCHAIN=local
COPY docker/go-tools/compose-go-net.patch /tmp/compose-go-net.patch
RUN set -eux; \
    curl --fail --location --proto '=https' \
        https://codeload.github.com/tianon/gosu/tar.gz/29b939aa5bd4dc588af765b8248f6e39150d2565 -o /tmp/gosu.tar.gz; \
    echo '2b70da8a5480fafedc16bb1fc91b572f064d03df736cbd91a616af2f1b66ab51  /tmp/gosu.tar.gz' | sha256sum --check -; \
    mkdir -p /usr/src/gosu /out; \
    tar --extract --gzip --file=/tmp/gosu.tar.gz --strip-components=1 --directory=/usr/src/gosu; \
    cd /usr/src/gosu; \
    go build -mod=readonly -trimpath -ldflags='-d -w' -buildvcs=false -o /out/gosu .; \
    go mod verify; \
    go version -m /out/gosu; \
    /out/gosu --version; \
    /out/gosu nobody id; \
    curl --fail --location --proto '=https' \
        https://codeload.github.com/docker/compose/tar.gz/6cbc19a3fd59e305fb814dca4d854a4fb67d2941 -o /tmp/compose.tar.gz; \
    echo '2293b570f258dfc6e38e0bfdd3fa0e710324a7cf0449994304b524af8fb4f06c  /tmp/compose.tar.gz' | sha256sum --check -; \
    mkdir -p /usr/src/compose; \
    tar --extract --gzip --file=/tmp/compose.tar.gz --strip-components=1 --directory=/usr/src/compose; \
    cd /usr/src/compose; \
    git apply --unidiff-zero --check /tmp/compose-go-net.patch; \
    git apply --unidiff-zero /tmp/compose-go-net.patch; \
    go build -mod=readonly -trimpath \
        -ldflags='-s -w -X github.com/docker/compose/v5/internal.Version=5.6.1+sample.1' \
        -buildvcs=false -o /out/docker-compose ./cmd; \
    go mod verify; \
    go version -m /out/docker-compose; \
    /out/docker-compose version

FROM rust:1.99.1-slim-bookworm@sha256:24707e70a4f1e23d9a7b25202ec43126d28a52294b96aad5c670df1fd1f1d400 AS rust

RUN set -eux; \
    rm -rf /usr/local/rustup/downloads /usr/local/rustup/tmp; \
    find /usr/local/rustup/toolchains -type d \
        \( -path '*/share/doc' -o -path '*/share/man' \) \
        -prune -exec rm -rf '{}' +; \
    rustc --version; \
    cargo --version

FROM mariadb:11.4.13@sha256:d1891ede5a559f1086f55053bb8cc1569b0298338c4d72d71725a7f74f6ba620 AS mysql-seed

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
    test "$migration_count" = 50; \
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

FROM php:8.4.27-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76

COPY --from=brotli-build /out /usr/local/share/sample-app/brotli
RUN sh /usr/local/share/sample-app/brotli/install-vendor.sh /usr/local/share/sample-app/brotli \
    && php /usr/local/share/sample-app/brotli/verify-runtime.php \
    && rm -rf /var/lib/apt/lists/*

ARG VCS_REF=unknown
ARG DURABLE_WORKFLOW_CLI_VERSION

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
COPY --from=go-tools /out/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=go-tools /out/gosu /usr/local/bin/gosu
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
COPY docker/install-phpredis.sh /usr/local/bin/install-phpredis
COPY docker/install-npm.sh /usr/local/bin/install-npm
COPY .devcontainer/docker/verify-php-trust.php .devcontainer/docker/verify-node-trust.mjs /usr/local/share/sample-app/

RUN set -eux; \
    php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 && PHP_VERSION_ID >= 80427 ? 0 : 1);'; \
    php /usr/local/share/sample-app/verify-php-trust.php "$(command -v composer)"; \
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime; \
    echo "${TZ}" > /etc/timezone; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-mysql-client \
        ffmpeg \
        git \
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
    php /usr/local/share/sample-app/brotli/verify-runtime.php; \
    groupadd --gid 1000 laravel; \
    useradd --create-home --gid laravel --shell /bin/bash --uid 1000 laravel; \
    passwd --delete laravel; \
    install -d -m 0775 -o laravel -g laravel /home/laravel/.cargo /home/laravel/.composer /ms-playwright /var/www/html; \
    ln -s /usr/bin/python3 /usr/local/bin/python; \
    ln -s "$VIRTUAL_ENV/bin/pip" /usr/local/bin/pip; \
    ln -s "$VIRTUAL_ENV/bin/pip3" /usr/local/bin/pip3; \
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

COPY polyglot/qualified-artifact-tuple.json /usr/local/share/sample-app/qualified-artifact-tuple.json

RUN set -eux; \
    php /usr/local/share/sample-app/verify-php-trust.php "$(command -v composer)"; \
    node /usr/local/share/sample-app/verify-node-trust.mjs; \
    sh /usr/local/bin/install-npm; \
    cli_version="${DURABLE_WORKFLOW_CLI_VERSION:-$(php -r '$tuple = json_decode(file_get_contents("/usr/local/share/sample-app/qualified-artifact-tuple.json"), true, flags: JSON_THROW_ON_ERROR); echo $tuple["artifacts"]["cli"];')}"; \
    test -n "$cli_version"; \
    curl -fsSL https://durable-workflow.com/install.sh -o /tmp/dw-install.sh; \
    VERSION="$cli_version" \
        DURABLE_WORKFLOW_INSTALL_DIR=/usr/local/bin \
        sh /tmp/dw-install.sh; \
    rm /tmp/dw-install.sh; \
    dw --version

COPY playground/templates/python/requirements.txt /tmp/sample-app-python-playground-requirements.txt
COPY .devcontainer/docker/verify-python-trust.py /usr/local/share/sample-app/verify-python-trust.py
COPY docker/python /usr/local/share/sample-app/python

RUN set -eux; \
    python_sdk_version="$(php -r '$tuple = json_decode(file_get_contents("/usr/local/share/sample-app/qualified-artifact-tuple.json"), true, flags: JSON_THROW_ON_ERROR); echo $tuple["artifacts"]["sdk-python"];')"; \
    python_distribution_version="$(printf '%s' "$python_sdk_version" | sed 's/-rc\./rc/')"; \
    python3 /usr/local/share/sample-app/python/build-pip.py /tmp/sample-app-python-wheels --seed; \
    python3 -m venv "$VIRTUAL_ENV"; \
    "$VIRTUAL_ENV/bin/python" -I -m pip --isolated --disable-pip-version-check install \
        --no-index --no-deps --upgrade /tmp/sample-app-python-wheels/*.whl; \
    rm -rf /tmp/sample-app-python-wheels; \
    "$VIRTUAL_ENV/bin/python" /usr/local/share/sample-app/verify-python-trust.py --pip-only; \
    "$VIRTUAL_ENV/bin/pip" install \
        --no-input \
        --no-cache-dir \
        --disable-pip-version-check \
        --requirement /tmp/sample-app-python-playground-requirements.txt \
        "durable-workflow==${python_distribution_version}"; \
    EXPECTED_PYTHON_SDK="$python_distribution_version" \
        "$VIRTUAL_ENV/bin/python" -c 'import durable_workflow, importlib.metadata as metadata, os; assert metadata.version("durable-workflow") == os.environ["EXPECTED_PYTHON_SDK"]'; \
    rm /tmp/sample-app-python-playground-requirements.txt; \
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
    PLAYWRIGHT_DOWNLOAD_CONNECTION_TIMEOUT=120000 playwright install --with-deps --only-shell chromium; \
    python3 /usr/local/share/sample-app/python/build-pip.py --clean-seeds; \
    rm /usr/local/share/sample-app/python/build-pip.py /usr/local/share/sample-app/python/pip-urllib3.patch; \
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
        gosu laravel php -r '$tuple = json_decode(file_get_contents("/usr/local/share/sample-app/qualified-artifact-tuple.json"), true, flags: JSON_THROW_ON_ERROR); require $argv[1]."/vendor/autoload.php"; $expected = $tuple["artifacts"]["workflow"]; $actual = Composer\InstalledVersions::getPrettyVersion("durable-workflow/workflow"); if ($actual !== $expected) { fwrite(STDERR, "Workflow version in {$argv[1]}: expected {$expected}, found {$actual}\n"); exit(1); } echo "Verified Workflow {$actual} in {$argv[1]}\n";' "$dependency_dir"; \
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
COPY .devcontainer/docker/verify-dependencies /usr/local/bin/verify-devcontainer-dependencies
COPY .devcontainer/docker/verify-image.sh /usr/local/bin/verify-devcontainer-image
COPY .devcontainer/docker/with-disposable-composer-state /usr/local/bin/with-disposable-composer-state
COPY .devcontainer/docker/with-group-shared-umask /usr/local/bin/with-group-shared-umask
COPY .devcontainer/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .devcontainer/docker/php.ini /usr/local/etc/php/conf.d/99-laravel.ini

RUN chmod +x /usr/local/bin/seed-mysql-volume /usr/local/bin/start-container \
        /usr/local/bin/verify-devcontainer-dependencies \
        /usr/local/bin/verify-devcontainer-image /usr/local/bin/verify-prepared-permissions \
        /usr/local/bin/with-disposable-composer-state \
        /usr/local/bin/with-group-shared-umask \
        /usr/local/bin/wait-for-devcontainer-identity \
    && DEVCONTAINER_DEPENDENCY_SCOPE=baked \
        DEVCONTAINER_SSH_HOST_KEY_STATE=absent \
        gosu laravel verify-devcontainer-image \
    && php /usr/local/share/sample-app/brotli/verify-runtime.php

EXPOSE 22 80 5173

ENTRYPOINT ["start-container"]
