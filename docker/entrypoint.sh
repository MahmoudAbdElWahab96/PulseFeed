#!/bin/sh
set -e

cd /var/www

# The image bakes a production vendor tree against the baked source. In
# development the source is bind-mounted over /var/www, so regenerate the
# autoloader to match the mounted code when asked to.
if [ "${REBUILD_AUTOLOADER:-0}" = "1" ]; then
    composer dump-autoload --no-interaction
fi

# Only the app service migrates (RUN_MIGRATIONS=1). Migrations complete
# before php-fpm starts serving, so nginx and the queue worker - which
# depend on app - can never observe a half-migrated database. The queue
# container only consumes jobs that API requests create, and API requests
# only arrive after migrations are done.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
