<?php

// Guard before Laravel/PHPUnit can bootstrap or refresh any database.
$expected = ['APP_ENV' => 'testing', 'DB_HOST' => 'delivery-mysql-test',
    'DB_DATABASE' => 'shishli_delivery_release_test', 'DB_CONNECTION' => 'mysql', 'DB_USERNAME' => 'root'];
foreach ($expected as $key => $value) {
    if (getenv($key) !== $value) {
        throw new RuntimeException('Refusing non-isolated delivery test environment: '.$key);
    }
}
if (getenv('DB_URL') || is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing connection URLs or cached application configuration.');
}
$pdo = new PDO('mysql:host=delivery-mysql-test;port=3306;dbname=shishli_delivery_release_test', 'root', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.')) {
    throw new RuntimeException('MySQL 8.4 is required.');
}
$pdo = null;
chdir(dirname(__DIR__, 2));
$arguments = [PHP_BINARY, 'vendor/bin/phpunit', '--no-progress',
    'tests/Feature/DeliveryExceptionsMysqlConcurrencyTest.php', 'tests/Feature/DriverAcceptanceConcurrencyTest.php',
    'tests/Feature/DeliveryExceptionsTest.php', 'tests/Feature/InternalDeliveryTest.php',
    'tests/Feature/CashPaymentTest.php', 'tests/Feature/PaymentWebhookTest.php', 'tests/Feature/OrderCancellationTest.php'];
$process = proc_open($arguments, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot launch isolated delivery tests.');
}
exit(proc_close($process));
