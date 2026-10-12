<?php

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);
if (is_file($root.'/back-end/bootstrap/cache/config.php')) {
    throw new RuntimeException('Inspect uncached local configuration only.');
}
require $root.'/back-end/vendor/autoload.php';
$app = require $root.'/back-end/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$report = [
    'scope' => 'Current local configuration, never production validation; no credentials included',
    'app_environment' => $app->environment(),
    'debug_enabled' => (bool) config('app.debug'),
    'app_key_present' => filled(config('app.key')),
    'app_url_uses_https' => parse_url(config('app.url'), PHP_URL_SCHEME) === 'https',
    'database_connection' => config('database.default'),
    'database_password_present' => filled(config('database.connections.'.config('database.default').'.password')),
    'session_driver' => config('session.driver'),
    'session_encrypted' => config('session.encrypt'),
    'session_secure_cookie' => config('session.secure'),
    'auth' => 'Sanctum bearer personal access tokens; cookie stateful middleware not enabled',
    'token_expiration_minutes' => config('sanctum.expiration'),
    'cors_wildcard' => in_array('*', config('cors.allowed_origins', []), true),
    'cors_credentials' => config('cors.supports_credentials'),
    'cache_store' => config('cache.default'),
    'rate_limiter_store' => config('cache.limiter') ?: config('cache.default'),
    'queue_connection' => config('queue.default'),
    'notification_queue_connection' => config('outbound_notifications.connection'),
    'external_notification_channels' => array_map(fn (array $channel): bool => $channel['enabled'], config('outbound_notifications.channels')),
    'payment_provider_count' => count(config('payments.providers')),
    'payment_default_provider_configured' => filled(config('payments.default_provider')),
    'filesystem_default' => config('filesystems.default'),
    'media_disk' => config('filesystems.media_disk'),
    'logging_channel' => config('logging.default'),
    'logging_level' => config('logging.channels.single.level'),
    'mail_driver' => config('mail.default'),
    'php_gd' => extension_loaded('gd'),
    'php_webp_encoder' => function_exists('imagewebp'),
    'trusted_proxy_allowlist_present' => filled(config('trustedproxy.proxies')),
    'public_storage_path_exists' => is_dir($root.'/back-end/public/storage'),
];
file_put_contents($root.'/docs/release/evidence/local-configuration.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Saved local configuration flags only; no secret values or database queries.'.PHP_EOL;
