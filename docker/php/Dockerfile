FROM php:8.3.35-fpm-bookworm AS runtime

# Build extensions once, retain their shared libraries, and remove toolchains.
# Keep Debian Bookworm and glibc; no application dependency changes are needed.
RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libonig-dev libsqlite3-dev libwebp-dev libfcgi-bin; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j2 pdo_mysql pdo_sqlite mbstring intl zip gd bcmath pcntl opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual ca-certificates curl libfcgi-bin apt debian-archive-keyring; \
    find /usr/local -type f \( -executable -o -name '*.so' \) -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print "*" so }' \
        | sort -u | xargs -r dpkg-query --search \
        | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    php -m; \
    php-fpm -t
WORKDIR /var/www/html
COPY back-end/ ./
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
COPY docker/php/php.ini /usr/local/etc/php/conf.d/shishli.ini
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-shishli.conf
COPY docker/php/entrypoint.sh /usr/local/bin/shishli-entrypoint
RUN chmod +x /usr/local/bin/shishli-entrypoint
ENTRYPOINT ["shishli-entrypoint"]
CMD ["php-fpm"]
FROM runtime AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip git && rm -rf /var/lib/apt/lists/*
FROM dependencies AS testing
RUN touch .env && composer install --no-interaction --prefer-dist --no-progress && chown -R www-data:www-data storage bootstrap/cache
FROM dependencies AS production-dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
FROM runtime AS production
COPY --from=production-dependencies /var/www/html/vendor ./vendor
COPY --from=production-dependencies /var/www/html/bootstrap/cache ./bootstrap/cache
RUN chown -R www-data:www-data storage bootstrap/cache
