<?php

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);
$database = 'shishli_release_test_'.bin2hex(random_bytes(8));
if (! preg_match('/^shishli_release_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid isolated test schema.');
}
if (is_file($root.'/back-end/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing cached application configuration.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'BCRYPT_ROUNDS' => '4', 'DRIVER_MYSQL_CONCURRENCY' => '0'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/back-end/vendor/autoload.php';
$app = require $root.'/back-end/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
if ($connection['database'] !== $database || filled($connection['url'])) {
    throw new RuntimeException('Isolated test connection mismatch.');
}
$pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$exit = 1;
try {
    $process = proc_open([
        PHP_BINARY, 'vendor/bin/phpunit',
        '--filter', 'CheckoutTest|InternalDeliveryTest|DemoDataSeederTest|MenuTest|ReportsTest',
        '--log-junit', $root.'/docs/release/evidence/mysql-functional.xml',
    ], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root.'/back-end');
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start isolated MySQL tests.');
    }
    $exit = proc_close($process);
} finally {
    $pdo->exec('DROP DATABASE `'.$database.'`');
    file_put_contents($root.'/docs/release/evidence/mysql-functional-isolation.json', json_encode([
        'schema' => $database,
        'connection_verified' => true,
        'engine' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'ephemeral_schema_removed' => true,
        'test_exit_code' => $exit,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
exit($exit);
