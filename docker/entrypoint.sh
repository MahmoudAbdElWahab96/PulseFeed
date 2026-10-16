#!/bin/sh
set -e

cd /var/www

if [ "${REBUILD_AUTOLOADER:-0}" = "1" ]; then
    composer dump-autoload --no-interaction
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
