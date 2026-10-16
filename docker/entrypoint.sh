#!/bin/sh
set -e

cd /app

# Storage permissions (mounted volumes may have reset them).
mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

ROLE="${ROLE:-web}"

echo "Waiting for database…"
ATTEMPTS=0
until php artisan db:monitor --databases=pgsql > /dev/null 2>&1; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge 30 ]; then
        echo "Database not reachable after 30 attempts, aborting." >&2
        exit 1
    fi
    sleep 2
done

# One-time boot tasks, run by the web role only (see config/deploy.yml).
if [ "$ROLE" = "web" ] && [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --isolated
fi

# Cache configuration for the worker processes.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
