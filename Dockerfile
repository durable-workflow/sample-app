FROM php:8.4-cli AS base

RUN apt-get update && apt-get install -y \
    curl ffmpeg libnspr4 libnss3 libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql pcntl zip bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── Dependencies ─────────────────────────────────────────
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# ── Frontend assets ──────────────────────────────────────
FROM node:22-slim AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci

COPY resources/ resources/
COPY vite.config.js ./
RUN npm run build

# ── Production image ─────────────────────────────────────
FROM base AS production

COPY --from=vendor /app /app
COPY --from=assets /usr/local/bin/node /usr/local/bin/node
COPY --from=assets /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=assets /app/node_modules /app/node_modules
COPY --from=assets /app/public/build /app/public/build
COPY .env.example /app/.env.example

RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && npx playwright install chromium

# Create .env so artisan commands work at build time
RUN cp .env.example .env 2>/dev/null || echo "APP_KEY=" > .env
RUN php artisan key:generate --force

# Publish Waterline assets
RUN php artisan vendor:publish --tag=waterline-assets --force 2>/dev/null || true

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

EXPOSE 8000

ENTRYPOINT ["app-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
