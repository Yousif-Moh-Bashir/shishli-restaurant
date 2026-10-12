<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$manifest = $root.'/front-end/.performance-check/audit-mysql.json';
$fixture = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $fixture['database'])) {
    throw new RuntimeException('Refusing to remove a non-audit database');
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
$database = DB::connection();
if ($database->getDatabaseName() !== $fixture['database']) {
    throw new RuntimeException('Audit connection mismatch');
}
$sqliteManifest = $root.'/front-end/.performance-check/audit-sqlite.json';
$expected = null;
if (is_file($sqliteManifest)) {
    $sqlite = json_decode(file_get_contents($sqliteManifest), true, flags: JSON_THROW_ON_ERROR);
    $expected = realpath($root.'/back-end/storage/framework/testing/performance-audit.sqlite');
    if ($expected === false || realpath($sqlite['database']) !== $expected) {
        throw new RuntimeException('Refusing to remove an unexpected SQLite file');
    }
}
$database->statement('DROP DATABASE `'.$fixture['database'].'`');
DB::disconnect();
if (($expected !== null && (! unlink($expected) || ! unlink($sqliteManifest))) || ! unlink($manifest)) {
    throw new RuntimeException('Audit fixture cleanup failed');
}
file_put_contents($root.'/docs/performance/cleanup.json', json_encode(['isolated_mysql_schema_removed' => true, 'isolated_sqlite_file_removed' => true, 'private_token_manifests_removed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Removed only isolated audit databases and private token manifests'.PHP_EOL;
