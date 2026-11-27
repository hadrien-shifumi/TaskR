#!/bin/sh
set -e

cd /app

# Unusable configurations stop here, with a plain message: before anything
# touches the storage or waits for the database.
/usr/local/bin/preflight.sh

# Storage skeleton and permissions: a mounted volume may start out empty,
# or arrive with its ownership reset. POSIX sh (dash here) has no brace
# expansion, so every directory is spelled out.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/cache/locks \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    storage/app/private \
    storage/app/public \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Role is inferred from the full container command: Octane serves the app
# (web), anything else (queue:work…) is a worker. Only web runs migrations.
case "$*" in
    *octane*) ROLE=web ;;
    *) ROLE=worker ;;
esac

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

# One-time boot tasks, run by the web role only.
# No --isolated: the cache-lock table does not exist yet on a fresh database.
if [ "$ROLE" = "web" ] && [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Cache configuration for the worker processes.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
