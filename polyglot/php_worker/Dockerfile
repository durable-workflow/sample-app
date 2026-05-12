# Polyglot PHP workflow worker.
#
# Reuses the sample-app Laravel image (Composer-installed
# `durable-workflow/workflow` SDK plus the PHP-authored polyglot
# workflow class at `app/Workflows/Polyglot/PhpToPythonWorkflow.php`)
# and runs the polyglot polling worker entrypoint instead of the
# default Laravel HTTP service.
#
# Build context for this Dockerfile is the sample-app repository root,
# not the polyglot/ directory — see polyglot/docker-compose.yml.
FROM php:8.4-cli AS base

RUN apt-get update && apt-get install -y \
    curl libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql pcntl zip bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# Match the sample-app .env contract: artisan refuses to boot without a
# real APP_KEY, so generate one for this image. The polyglot worker
# never serves HTTP traffic, so this key is throwaway.
RUN cp .env.example .env && php artisan key:generate --force

CMD ["php", "artisan", "app:polyglot-worker"]
