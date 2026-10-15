FROM php:8.4-fpm-alpine AS base

# install-php-extensions: compiles and enables PHP extensions cleanly.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql redis opcache

# Build dependencies in a dedicated stage so the final image carries only
# the composer binary, not the toolchain.
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www

# Vendor tree from the cache stage; source is bind-mounted in development,
# so autoloading is rebuilt at container start by the entrypoint.
COPY --from=vendor /var/www/vendor /var/www/vendor
COPY . .
RUN composer dump-autoload -o --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
