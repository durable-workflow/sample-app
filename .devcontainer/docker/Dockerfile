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
    TZ=UTC \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

WORKDIR /var/www/html

COPY --from=node /usr/local/ /usr/local/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/install-phpredis.sh /usr/local/bin/install-phpredis
COPY package-lock.json /tmp/sample-app-package-lock.json

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
    playwright_version="$(node -e 'const lock = require("/tmp/sample-app-package-lock.json"); process.stdout.write(lock.packages["node_modules/playwright"].version)')"; \
    test -n "$playwright_version"; \
    npm install --global "playwright@${playwright_version}"; \
    playwright install --with-deps chromium; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*; \
    setcap 'cap_net_bind_service=+ep' "$(command -v php)"; \
    getcap "$(command -v php)" | grep -F 'cap_net_bind_service=ep'; \
    groupadd --gid 1000 laravel; \
    useradd --create-home --gid laravel --shell /bin/bash --uid 1000 laravel; \
    passwd --delete laravel; \
    install -d -o laravel -g laravel /home/laravel/.composer /ms-playwright /var/www/html; \
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

ENV COMPOSER_HOME=/home/laravel/.composer \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

COPY .devcontainer/docker/start-container /usr/local/bin/start-container
COPY .devcontainer/docker/verify-image.sh /usr/local/bin/verify-devcontainer-image
COPY .devcontainer/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .devcontainer/docker/php.ini /usr/local/etc/php/conf.d/99-laravel.ini

RUN chmod +x /usr/local/bin/start-container /usr/local/bin/verify-devcontainer-image \
    && DEVCONTAINER_SSH_HOST_KEY_STATE=absent gosu laravel verify-devcontainer-image

EXPOSE 22 80 5173

ENTRYPOINT ["start-container"]
