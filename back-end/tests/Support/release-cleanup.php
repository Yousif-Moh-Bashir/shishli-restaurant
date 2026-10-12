<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$manifest = $root.'/front-end/.performance-check/audit-mysql.json';
$expected = $argv[1] ?? '';
if (! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $expected)) {
    throw new RuntimeException('An explicitly named synthetic schema is required.');
}
$fixture = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
if ($fixture['database'] !== $expected || is_file($root.'/back-end/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing unexpected schema or cached configuration.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $expected, 'DB_URL' => '', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/back-end/vendor/autoload.php';
$app = require $root.'/back-end/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($connection->getDatabaseName() !== $expected) {
    throw new RuntimeException('Isolated cleanup connection mismatch.');
}
$connection->statement('DROP DATABASE `'.$expected.'`');
DB::disconnect();
if (! unlink($manifest)) {
    throw new RuntimeException('Synthetic token manifest removal failed.');
}
file_put_contents($root.'/docs/release/evidence/cleanup.json', json_encode([
    'isolated_mysql_schema_removed' => true,
    'private_token_manifest_removed' => true,
    'current_restaurant_database_modified' => false,
    'existing_uploaded_media_modified' => false,
    'sqlite_fixture_created_or_removed' => false,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Removed only the explicitly verified synthetic schema and its private token manifest.'.PHP_EOL;
