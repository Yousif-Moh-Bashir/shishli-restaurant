# Local-only rebuild using already installed, lock-matched Linux dependencies.
# The source image is read only; Telegram and ordinary application images stay unchanged.
ARG DIAGNOSTIC_DEPENDENCIES_IMAGE=shishli-telegram-diagnostic:local
FROM ${DIAGNOSTIC_DEPENDENCIES_IMAGE}
WORKDIR /var/www/html
RUN sha256sum composer.lock | cut -d ' ' -f 1 > /tmp/diagnostic-composer-lock.sha256
COPY back-end/ ./
RUN test "$(sha256sum composer.lock | cut -d ' ' -f 1)" = "$(cat /tmp/diagnostic-composer-lock.sha256)" \
    && composer check-platform-reqs --no-dev --no-interaction \
    && COMPOSER_DISABLE_NETWORK=1 composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader \
    && php artisan package:discover --no-interaction \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm /tmp/diagnostic-composer-lock.sha256
