# Laravel host for Waterline and the embedded Workflow engine. This image is
# deliberately separate from the framework-neutral PHP worker image.
FROM php:8.4-cli

ARG DURABLE_WORKFLOW_PHP_SDK_PIN=
ARG DURABLE_WORKFLOW_PHP_SDK_VERSION
ARG DURABLE_WORKFLOW_WORKFLOW_PIN=
ARG DURABLE_WORKFLOW_WATERLINE_PIN=
ARG DURABLE_WORKFLOW_WORKFLOW_VERSION
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
RUN composer dump-autoload --optimize \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8081"]
