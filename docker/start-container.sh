#!/usr/bin/env sh
set -eu

cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Set it from Coolify or your runtime environment." >&2
    exit 1
fi

# First boot can run before database-backed cache/session tables exist.
# Do not let cache cleanup kill the container before migrations create them.
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
CACHE_STORE=array php artisan cache:clear || true
php artisan optimize:clear || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

if [ "${RUN_PANEL_SEED:-true}" = "true" ]; then
    php artisan db:seed --class=PanelMetadataSeeder --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec frankenphp run --config /etc/caddy/Caddyfile
