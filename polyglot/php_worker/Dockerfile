# Framework-neutral PHP worker for the standalone Durable Workflow server.
# The build context is polyglot/php_worker, so no Laravel application source
# or embedded Workflow engine package is available to this image.
FROM php:8.4-cli

ARG DURABLE_WORKFLOW_PHP_SDK_PIN=
ARG DURABLE_WORKFLOW_PHP_SDK_VERSION

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev unzip \
    && docker-php-ext-install curl pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN test -n "$DURABLE_WORKFLOW_PHP_SDK_VERSION" \
    && constraint="${DURABLE_WORKFLOW_PHP_SDK_PIN#durable-workflow/sdk:}" \
    && constraint="${constraint%@*}" \
    && if [ -z "$constraint" ]; then constraint="$DURABLE_WORKFLOW_PHP_SDK_VERSION"; fi \
    && composer require --no-interaction --prefer-dist --optimize-autoloader \
        "durable-workflow/sdk:${constraint}" \
    && composer show durable-workflow/sdk \
    && composer show apache/avro \
    && ! composer show durable-workflow/workflow \
    && ! composer show laravel/framework

COPY worker.php ./

CMD ["php", "/app/worker.php"]
