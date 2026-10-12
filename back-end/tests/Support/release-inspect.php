<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$fixture = json_decode(file_get_contents($root.'/front-end/.performance-check/audit-mysql.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $fixture['database'])) {
    throw new RuntimeException('Only the isolated audit schema may be inspected.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $fixture['database'], 'DB_URL' => '', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file($root.'/back-end/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing cached application configuration.');
}
require $root.'/back-end/vendor/autoload.php';
$app = require $root.'/back-end/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($connection->getDatabaseName() !== $fixture['database']) {
    throw new RuntimeException('Audit connection mismatch.');
}
$report = [
    'server_version' => $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'isolated_schema_verified' => true,
    'migrations_applied' => $connection->table('migrations')->count(),
    'tables' => $connection->select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$fixture['database']]),
    'foreign_keys' => $connection->select('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$fixture['database']]),
    'indexes' => [],
    'plans' => [],
    'orphan_branch_products' => $connection->table('branch_products as bp')->leftJoin('branches as b', 'b.id', '=', 'bp.branch_id')->leftJoin('products as p', 'p.id', '=', 'bp.product_id')->where(fn (Builder $query): Builder => $query->whereNull('b.id')->orWhereNull('p.id'))->count(),
    'orphan_driver_branches' => $connection->table('users as u')->leftJoin('branches as b', 'b.id', '=', 'u.driver_branch_id')->whereNotNull('u.driver_branch_id')->whereNull('b.id')->count(),
];
foreach (['orders', 'branch_products', 'products', 'cart_items', 'driver_cash_collections', 'driver_cash_settlements'] as $table) {
    $report['indexes'][$table] = $connection->select('SHOW INDEX FROM '.$table);
}
$branch = $connection->table('branches')->where('uuid', $fixture['branch'])->value('id');
foreach ([
    'admin_business_day' => ['select * from orders where branch_id = ? and placed_at >= ? and placed_at < ? order by placed_at desc, id desc limit 15', [$branch, now()->startOfDay(), now()->addDay()->startOfDay()]],
    'branch_menu' => ['select * from branch_products where branch_id = ?', [$branch]],
    'driver_queue' => ["select * from orders where branch_id = ? and type = 'delivery' and driver_id is null and status = 'ready' order by placed_at limit 15", [$branch]],
] as $name => [$sql, $bindings]) {
    $report['plans'][$name] = $connection->select('EXPLAIN '.$sql, $bindings);
}
file_put_contents($root.'/docs/release/evidence/database.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['server_version' => $report['server_version'], 'migrations' => $report['migrations_applied'], 'tables' => count($report['tables']), 'foreign_keys' => count($report['foreign_keys']), 'orphan_branch_products' => $report['orphan_branch_products'], 'orphan_driver_branches' => $report['orphan_driver_branches']]).PHP_EOL;
