#!/usr/bin/env sh
set -u

cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Set it from Coolify or your runtime environment." >&2
    exit 1
fi

run_artisan() {
    if ! php artisan "$@"; then
        echo "WARN: php artisan $* failed; continuing container startup." >&2
    fi
}

run_artisan_array_cache() {
    if ! CACHE_STORE=array php artisan "$@"; then
        echo "WARN: CACHE_STORE=array php artisan $* failed; continuing container startup." >&2
    fi
}

# First boot can run before database-backed cache/session tables exist.
# Avoid database-backed cache during cleanup so migrations get a chance to run.
run_artisan_array_cache config:clear
run_artisan_array_cache route:clear
run_artisan_array_cache view:clear
run_artisan_array_cache cache:clear
run_artisan_array_cache optimize:clear

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    run_artisan migrate --force --no-interaction
fi

if [ "${RUN_PANEL_SEED:-true}" = "true" ]; then
    run_artisan db:seed --class=PanelMetadataSeeder --force --no-interaction
fi

run_artisan config:cache
run_artisan route:cache
run_artisan view:cache

exec frankenphp run --config /etc/caddy/Caddyfile
