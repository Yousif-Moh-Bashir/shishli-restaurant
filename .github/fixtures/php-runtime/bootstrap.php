<?php

declare(strict_types=1);

// Loaded only through the read-only /ci bind mount, never production autoload.
if (getenv('SHISHLI_RUNTIME_CI') !== '1'
    || ! preg_match('/^shishli_ci_[0-9]+_[0-9]+$/', (string) getenv('DB_DATABASE'))
    || getenv('DB_HOST') !== 'mysql'
    || getenv('REDIS_HOST') !== 'redis'
    || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "CI_GUARD_REJECTED\n");
    exit(90);
}

require '/var/www/html/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'ShishliRuntimeCi\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/', $name) === 1) {
            require __DIR__."/{$name}.php";
        }
    }
});

$app = require '/var/www/html/bootstrap/app.php';
$app->register(ShishliRuntimeCi\CiServiceProvider::class);

return $app;
