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
ARG DURABLE_WORKFLOW_PHP_SDK_VERSION
ARG DURABLE_WORKFLOW_WATERLINE_VERSION

RUN apt-get update && apt-get install -y \
    curl libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql pcntl zip bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
COPY scripts/install-composer-artifacts.sh /usr/local/bin/install-composer-artifacts
RUN bash /usr/local/bin/install-composer-artifacts

COPY . .
RUN composer dump-autoload --optimize

# Match the sample-app .env contract: artisan refuses to boot without a
# real APP_KEY, so generate one for this image. The polyglot worker
# never serves HTTP traffic, so this key is throwaway.
RUN cp .env.example .env && php artisan key:generate --force
RUN chmod +x docker/entrypoint.sh

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "artisan", "app:polyglot-worker"]
