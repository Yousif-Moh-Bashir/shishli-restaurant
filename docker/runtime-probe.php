<?php

use App\Jobs\SendNotificationJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || filled(config('database.connections.mysql.url')) || config('database.connections.mysql.host') !== 'mysql' || config('database.connections.mysql.database') !== 'shishli') {
    throw new RuntimeException('Unexpected runtime database.');
}
$probeKey = 'docker-readiness-'.Str::uuid();
Cache::put($probeKey, 'ok', 60);
if (Cache::pull($probeKey) !== 'ok') {
    throw new RuntimeException('Redis cache read/write failed.');
}
DB::select('SELECT 1');
// An absent notification is handled as a no-op by the existing job action.
$job = new SendNotificationJob((string) Str::uuid());
Queue::connection('redis')->push($job, '', 'notifications');
echo json_encode(['database' => 'mysql/shishli', 'cache' => 'redis read/write OK', 'queue_size_after_enqueue' => Queue::connection('redis')->size('notifications'), 'users' => DB::table('users')->count(), 'failed_jobs' => DB::table('failed_jobs')->count()], JSON_PRETTY_PRINT).PHP_EOL;
