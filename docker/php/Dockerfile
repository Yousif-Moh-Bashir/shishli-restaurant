FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libsqlite3-dev unzip git libfcgi-bin && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j2 pdo_mysql pdo_sqlite mbstring intl zip gd bcmath pcntl opcache && pecl install redis && docker-php-ext-enable redis && rm -rf /var/lib/apt/lists/*
RUN apt-get update && apt-get install -y --no-install-recommends libwebp-dev && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && docker-php-ext-install -j2 gd && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY back-end/ ./
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
COPY docker/php/php.ini /usr/local/etc/php/conf.d/shishli.ini
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-shishli.conf
COPY docker/php/entrypoint.sh /usr/local/bin/shishli-entrypoint
RUN chmod +x /usr/local/bin/shishli-entrypoint
ENTRYPOINT ["shishli-entrypoint"]
CMD ["php-fpm"]
FROM base AS testing
RUN touch .env && composer install --no-interaction --prefer-dist --no-progress && chown -R www-data:www-data storage bootstrap/cache
FROM base AS production
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader && chown -R www-data:www-data storage bootstrap/cache
