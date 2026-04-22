#!/usr/bin/env sh
set -eu

# Ensure runtime environment vars override the build-time .env
# Laravel reads .env, but Docker Compose env vars take precedence
# only when config is NOT cached. Clear any cached config.
php artisan config:clear 2>/dev/null || true

# Write a minimal .env from the current environment so artisan
# commands that read .env directly (e.g., key:generate) work.
cat > /app/.env << ENVEOF
APP_KEY=${APP_KEY:-$(grep APP_KEY /app/.env.example 2>/dev/null | cut -d= -f2- || echo "")}
APP_ENV=${APP_ENV:-local}
APP_DEBUG=${APP_DEBUG:-true}
APP_URL=${APP_URL:-http://localhost:8000}
DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-sample}
DB_USERNAME=${DB_USERNAME:-laravel}
DB_PASSWORD=${DB_PASSWORD:-password}
SHARED_DB_HOST=${SHARED_DB_HOST:-${DB_HOST:-127.0.0.1}}
SHARED_DB_PORT=${SHARED_DB_PORT:-${DB_PORT:-3306}}
SHARED_DB_DATABASE=${SHARED_DB_DATABASE:-${DB_DATABASE:-sample}}
SHARED_DB_USERNAME=${SHARED_DB_USERNAME:-${DB_USERNAME:-laravel}}
SHARED_DB_PASSWORD=${SHARED_DB_PASSWORD:-${DB_PASSWORD:-password}}
REDIS_HOST=${REDIS_HOST:-127.0.0.1}
QUEUE_CONNECTION=${QUEUE_CONNECTION:-redis}
CACHE_STORE=${CACHE_STORE:-redis}
SESSION_DRIVER=${SESSION_DRIVER:-redis}
ENVEOF

# Generate APP_KEY if not set
if [ -z "$(grep 'APP_KEY=base64:' /app/.env 2>/dev/null)" ]; then
    php artisan key:generate --force 2>/dev/null || true
fi

# Migrations are intentionally explicit. Running them from every service
# entrypoint can race on fresh databases and leave partially recorded schema.
php artisan vendor:publish --tag=waterline-assets --force 2>/dev/null || true

exec "$@"
