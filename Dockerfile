FROM composer:2.11.0@sha256:278dfc7bd35169965d03f87194816f43bf4e6b6ea61707c2114e52ad12fe24ce AS composer

FROM php:8.4.27-cli-bookworm@sha256:b3e907a1108629c562c4bb313ce3449e34d49991f6bb2487913f6146f47200fe AS brotli-build

COPY .central-apt-source/docker/apt/require-fixed-bookworm-apt.sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/verify-runtime-trust.php /usr/local/share/sample-app/verify-runtime-trust.php
COPY docker/brotli/version docker/brotli/install-vendor.sh docker/brotli/verify-runtime.php docker/brotli/verify-system-brotli.c /usr/src/brotli-inputs/
RUN /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh \
    && php /usr/local/share/sample-app/verify-runtime-trust.php /usr/local/bin/composer \
    && sh /usr/src/brotli-inputs/install-vendor.sh /usr/src/brotli-inputs gcc libc6-dev \
    && mkdir -p /out \
    && cc -O2 /usr/src/brotli-inputs/verify-system-brotli.c -lbrotlidec -lbrotlienc -ldl -o /out/verify-system-brotli \
    && /out/verify-system-brotli \
    && cp /usr/src/brotli-inputs/version /usr/src/brotli-inputs/install-vendor.sh /usr/src/brotli-inputs/verify-runtime.php /out/

FROM php:8.4.27-cli-bookworm@sha256:b3e907a1108629c562c4bb313ce3449e34d49991f6bb2487913f6146f47200fe AS base

COPY .central-apt-source/docker/apt/require-fixed-bookworm-apt.sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh
COPY --from=brotli-build /out /usr/local/share/sample-app/brotli
RUN /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh \
    && sh /usr/local/share/sample-app/brotli/install-vendor.sh /usr/local/share/sample-app/brotli \
    && php /usr/local/share/sample-app/brotli/verify-runtime.php \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/install-phpredis.sh /usr/local/bin/install-phpredis
COPY docker/verify-runtime-trust.php docker/verify-node-trust.mjs /usr/local/share/sample-app/

RUN /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh \
    && php /usr/local/share/sample-app/verify-runtime-trust.php "$(command -v composer)" \
    && apt-get update && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends \
    curl ffmpeg git libnspr4 libnss3 libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo pdo_mysql pcntl zip bcmath \
    && sh /usr/local/bin/install-phpredis \
    && php /usr/local/share/sample-app/brotli/verify-runtime.php \
    && php /usr/local/share/sample-app/verify-runtime-trust.php "$(command -v composer)" \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ── Dependencies ─────────────────────────────────────────
FROM base AS vendor

ARG DURABLE_WORKFLOW_PHP_SDK_PIN=
ARG DURABLE_WORKFLOW_PHP_SDK_VERSION=
ARG DURABLE_WORKFLOW_WORKFLOW_PIN=
ARG DURABLE_WORKFLOW_WATERLINE_PIN=
ARG DURABLE_WORKFLOW_WORKFLOW_VERSION=
ARG DURABLE_WORKFLOW_WATERLINE_VERSION=
ARG SAMPLE_APP_COMMIT=

COPY composer.json composer.lock ./
COPY scripts/install-composer-artifacts.sh /usr/local/bin/install-composer-artifacts
RUN php /usr/local/share/sample-app/verify-runtime-trust.php "$(command -v composer)" \
    && bash /usr/local/bin/install-composer-artifacts

COPY . .
RUN rm -rf /app/.central-apt-source \
    && composer dump-autoload --optimize

# ── Frontend assets ──────────────────────────────────────
FROM node:22.23.4-bookworm-slim@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS assets

WORKDIR /app
COPY docker/verify-node-trust.mjs /usr/local/share/sample-app/verify-node-trust.mjs
COPY docker/install-npm.sh /usr/local/bin/install-npm
COPY package.json package-lock.json* ./
RUN node /usr/local/share/sample-app/verify-node-trust.mjs \
    && sh /usr/local/bin/install-npm \
    && npm ci --ignore-scripts

COPY resources/ resources/
COPY vite.config.js ./
RUN npm run build

# ── Production image ─────────────────────────────────────
FROM base AS production

ARG SAMPLE_APP_COMMIT=
ENV SAMPLE_APP_COMMIT=${SAMPLE_APP_COMMIT} \
    HOME=/home/www-data \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
LABEL org.opencontainers.image.revision="${SAMPLE_APP_COMMIT}"

COPY --from=vendor /app /app
COPY --from=assets /usr/local/bin/node /usr/local/bin/node
COPY --from=assets /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=assets /app/node_modules /app/node_modules
COPY --from=assets /app/public/build /app/public/build
COPY .env.example /app/.env.example

RUN /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh \
    && node /usr/local/share/sample-app/verify-node-trust.mjs \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && PLAYWRIGHT_DOWNLOAD_CONNECTION_TIMEOUT=120000 ./node_modules/.bin/playwright install --with-deps chromium \
    && node docker/playwright-smoke.js \
    && node /usr/local/share/sample-app/verify-node-trust.mjs \
    && php /usr/local/share/sample-app/brotli/verify-runtime.php \
    && php /usr/local/share/sample-app/verify-runtime-trust.php "$(command -v composer)" \
    && (cp .env.example .env 2>/dev/null || echo "APP_KEY=" > .env) \
    && php artisan key:generate --force \
    && (php artisan vendor:publish --tag=waterline-assets --force 2>/dev/null || true)

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint \
    && mkdir -p /app/videos "$HOME" "$PLAYWRIGHT_BROWSERS_PATH" \
    && chown -R www-data:www-data /app "$HOME" "$PLAYWRIGHT_BROWSERS_PATH"

USER www-data

EXPOSE 8000

ENTRYPOINT ["app-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
