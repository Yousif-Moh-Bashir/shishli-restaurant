<?php

use App\Actions\Drivers\AcceptDeliveryOrderAction;
use App\Actions\Drivers\ManageDeliveryExceptionAction;
use App\Actions\Drivers\TransitionDeliveryAction;
use App\Enums\DeliveryStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$job = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== 'delivery-mysql-test'
    || ! preg_match('/^shishli_delivery_race_[a-f0-9]{16}$/D', $job['database'] ?? '')
    || is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing non-isolated race worker.');
}
putenv('DB_DATABASE='.$job['database']);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $job['database'];
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $job['database']) {
    throw new RuntimeException('Worker connection mismatch.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$actor = User::findOrFail($job['actor']);
$order = Order::findOrFail($job['order']);
echo "READY\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('Missing race barrier.');
}
echo "START\n";
fflush(STDOUT);
try {
    match ($job['kind']) {
        'accept' => app(AcceptDeliveryOrderAction::class)->handle($actor, $order),
        'deliver' => app(TransitionDeliveryAction::class)->handle($actor, $order, DeliveryStatus::Delivered, null),
        'exception' => app(ManageDeliveryExceptionAction::class)->handle($actor, $order, $job['payload']),
    };
    echo "200\n";
} catch (HttpException $exception) {
    echo $exception->getStatusCode()."\n";
} catch (Throwable) {
    // Test credentials and exception traces must not escape the worker.
    fwrite(STDERR, "Race worker failed unexpectedly.\n");
    exit(1);
}
