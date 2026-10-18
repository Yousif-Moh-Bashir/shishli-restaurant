FROM php:8.3.35-fpm-alpine3.24 AS runtime
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache ca-certificates fcgi; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS pax-utils \
        icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev sqlite-dev libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j2 pdo_mysql pdo_sqlite mbstring intl zip gd bcmath pcntl opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    runDeps="$(scanelf --needed --nobanner --format '%n#p' --recursive /usr/local | tr ',' '\n' | sort -u | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }')"; \
    apk add --no-cache --virtual .shishli-rundeps $runDeps; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear; \
    php -m; \
    php-fpm -t
RUN apk add --no-cache icu-data-full tzdata
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
RUN apk add --no-cache unzip git
FROM dependencies AS testing
RUN touch .env && composer install --no-interaction --prefer-dist --no-progress && chown -R www-data:www-data storage bootstrap/cache
FROM dependencies AS production-dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
FROM runtime AS production
COPY --from=production-dependencies /var/www/html/vendor ./vendor
COPY --from=production-dependencies /var/www/html/bootstrap/cache ./bootstrap/cache
RUN chown -R www-data:www-data storage bootstrap/cache
