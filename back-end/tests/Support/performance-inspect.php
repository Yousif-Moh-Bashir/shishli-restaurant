<?php

use App\Models\Branch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$fixture = json_decode(file_get_contents(__DIR__.'/../../../front-end/.performance-check/audit-mysql.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $fixture['database'])) {
    throw new RuntimeException('Only the isolated audit schema may be inspected');
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
$db = DB::connection();
$version = $db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
$branch = Branch::where('uuid', $fixture['branch'])->firstOrFail();
$queries = [
    'admin_business_day' => ['select * from orders where branch_id = ? and placed_at >= ? and placed_at < ? order by placed_at desc, id desc limit 15', [$branch->id, now()->startOfDay()->toDateTimeString(), now()->addDay()->startOfDay()->toDateTimeString()]],
    'branch_menu' => ['select * from branch_products where branch_id = ?', [$branch->id]],
    'driver_queue' => ["select * from orders where branch_id = ? and type = 'delivery' and driver_id is null and status = 'ready' order by placed_at limit 15", [$branch->id]],
];
$report = ['server_version' => $version, 'fixture_counts' => $fixture['counts'], 'indexes' => [], 'plans' => [], 'server_profiles' => null];
foreach (['orders', 'branch_products', 'cart_items', 'products'] as $table) {
    $report['indexes'][$table] = $db->select('SHOW INDEX FROM '.$table);
}
if (str_contains(strtolower($version), 'mariadb')) {
    $db->statement('SET profiling = 1');
}
foreach ($queries as $name => [$sql, $bindings]) {
    $report['plans'][$name] = $db->select('EXPLAIN '.$sql, $bindings);
    $started = hrtime(true);
    $db->select($sql, $bindings);
    $report['client_query_elapsed_ms'][$name] = (hrtime(true) - $started) / 1e6;
}
if (str_contains(strtolower($version), 'mariadb')) {
    $report['server_profiles'] = $db->select('SHOW PROFILES');
}
file_put_contents(__DIR__.'/../../../docs/performance/mysql-plans.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode(['server_version' => $version, 'plans' => array_keys($report['plans'])]).PHP_EOL;
