<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if ($app->environment() !== 'testing' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== 'finance-mysql'
    || config('database.connections.mysql.database') !== 'shishli_finance_test'
    || config('database.connections.mysql.url')) {
    fwrite(STDERR, "Refusing non-isolated database.\n");
    exit(1);
}
$job = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
echo "READY\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') {
    exit(1);
}
echo "START\n";
fflush(STDOUT);
$request = Request::create($job['uri'], 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
], content: json_encode($job['payload'], JSON_THROW_ON_ERROR));
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
echo $response->getStatusCode()."\n";
$kernel->terminate($request, $response);
