<?php

use App\Models\PrintAgent;
use App\Services\Printing\AcceptedOrderPrinting;
use App\Services\Printing\PrintJobBroker;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'shishli_printing_test') {
    exit(90);
}
config(['printing.enabled' => true]);
if ($argv[1] === 'capture') {
    app(AcceptedOrderPrinting::class)->capture((int) $argv[2]);
    echo '{}';
} else {
    $agent = PrintAgent::where('uuid', $argv[2])->firstOrFail();
    echo json_encode(app(PrintJobBroker::class)->claim($agent, 'preview'));
}
