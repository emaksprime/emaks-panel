#!/usr/bin/env sh
set -eu

cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Set it from Coolify or your runtime environment." >&2
    exit 1
fi

CACHE_STORE=array php artisan optimize:clear

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${RUN_PANEL_SEED:-true}" = "true" ]; then
    php artisan db:seed --class=PanelMetadataSeeder --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec frankenphp run --config /etc/caddy/Caddyfile
