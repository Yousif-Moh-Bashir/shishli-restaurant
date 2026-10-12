<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$root = dirname(__DIR__, 3);
$fixture = json_decode(file_get_contents($root.'/front-end/.performance-check/audit-mysql.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $fixture['database'])) {
    throw new RuntimeException('Only the isolated audit connection may bootstrap this utility');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $fixture['database'], 'DB_URL' => '', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing audit bootstrap with cached application configuration');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$status = Artisan::call('media:optimize', ['--directory' => 'products', '--no-interaction' => true]);
if ($status !== 0) {
    throw new RuntimeException('Media conversion failed');
}
$results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($root.'/docs/performance/product-image-optimization.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
