# Polyglot PHP worker.
#
# Reuses the sample-app Laravel image (Composer-installed
# `durable-workflow/workflow` SDK plus the polyglot PHP workflow and
# activity handlers) and runs the polyglot polling worker entrypoint
# instead of the default Laravel HTTP service.
#
# Build context for this Dockerfile is the sample-app repository root,
# not the polyglot/ directory — see polyglot/docker-compose.yml.
FROM php:8.4-cli AS base

ARG DURABLE_WORKFLOW_PHP_SDK_PIN=
ARG DURABLE_WORKFLOW_WATERLINE_PIN=
ARG DURABLE_WORKFLOW_PHP_SDK_VERSION=2.0.0-alpha.183
ARG DURABLE_WORKFLOW_WATERLINE_VERSION=2.0.0-alpha.66

RUN apt-get update && apt-get install -y \
    curl libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql pcntl zip bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN set -eux; \
    workflow_version="$DURABLE_WORKFLOW_PHP_SDK_VERSION"; \
    if [ -n "$DURABLE_WORKFLOW_PHP_SDK_PIN" ]; then \
        workflow_version="${DURABLE_WORKFLOW_PHP_SDK_PIN#durable-workflow/workflow:}"; \
    fi; \
    workflow_version="${workflow_version%@*}"; \
    waterline_version="$DURABLE_WORKFLOW_WATERLINE_VERSION"; \
    if [ -n "$DURABLE_WORKFLOW_WATERLINE_PIN" ]; then \
        waterline_version="${DURABLE_WORKFLOW_WATERLINE_PIN#durable-workflow/waterline:}"; \
    fi; \
    waterline_version="${waterline_version%@*}"; \
    if [ -n "$workflow_version" ] && [ -n "$waterline_version" ]; then \
        composer require --no-update \
            "durable-workflow/workflow:${workflow_version}" \
            "durable-workflow/waterline:${waterline_version}"; \
    elif [ -n "$workflow_version" ]; then \
        composer require --no-update "durable-workflow/workflow:${workflow_version}"; \
    elif [ -n "$waterline_version" ]; then \
        composer require --no-update "durable-workflow/waterline:${waterline_version}"; \
    fi; \
    composer update durable-workflow/workflow durable-workflow/waterline \
        --with-dependencies \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --optimize

# Match the sample-app .env contract: artisan refuses to boot without a
# real APP_KEY, so generate one for this image. The polyglot worker
# never serves HTTP traffic, so this key is throwaway.
RUN cp .env.example .env && php artisan key:generate --force
RUN chmod +x docker/entrypoint.sh

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "artisan", "app:polyglot-worker"]
