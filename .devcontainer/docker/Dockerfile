FROM node:22-bookworm-slim AS node

FROM composer:2 AS composer

FROM php:8.4-cli-bookworm

ARG VCS_REF=unknown

LABEL org.opencontainers.image.title="Durable Workflow Sample App development container" \
    org.opencontainers.image.description="PHP, Node, Composer, and Chromium environment for the Durable Workflow Sample App" \
    org.opencontainers.image.source="https://github.com/durable-workflow/sample-app" \
    org.opencontainers.image.revision="${VCS_REF}" \
    org.opencontainers.image.licenses="MIT"

ENV DEBIAN_FRONTEND=noninteractive \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    TZ=UTC \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

WORKDIR /var/www/html

COPY --from=node /usr/local/ /usr/local/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
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
        redis-tools \
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
    groupadd --gid 1000 laravel; \
    useradd --create-home --gid laravel --shell /bin/bash --uid 1000 laravel; \
    passwd --delete laravel; \
    install -d -o laravel -g laravel /home/laravel/.composer /ms-playwright /var/www/html; \
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
        install -d -o laravel -g laravel "${dependency_dir}/vendor"; \
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
    done; \
    rm -rf /home/laravel/.composer/cache; \
    rm -rf /tmp/* /var/tmp/*

ENV COMPOSER_HOME=/home/laravel/.composer

COPY .devcontainer/docker/start-container /usr/local/bin/start-container
COPY .devcontainer/docker/verify-image.sh /usr/local/bin/verify-devcontainer-image
COPY .devcontainer/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .devcontainer/docker/php.ini /usr/local/etc/php/conf.d/99-laravel.ini

RUN chmod +x /usr/local/bin/start-container /usr/local/bin/verify-devcontainer-image \
    && DEVCONTAINER_SSH_HOST_KEY_STATE=absent gosu laravel verify-devcontainer-image

EXPOSE 22 80 5173

ENTRYPOINT ["start-container"]
