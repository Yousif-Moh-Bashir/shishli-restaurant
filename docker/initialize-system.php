<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || filled(config('database.connections.mysql.url')) || config('database.connections.mysql.host') !== 'mysql' || config('database.connections.mysql.database') !== 'shishli') {
    throw new RuntimeException('Refusing initialization outside the Shishli Docker database.');
}
if (in_array('--verify-connection', $argv, true)) {
    echo "DOCKER_DATABASE_TARGET_VERIFIED\n";
    exit(0);
}
$usersBefore = DB::table('users')->count();
if (DB::table('roles')->count() === 0) {
    $exit = Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true, '--no-interaction' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('System role initialization failed.');
    }
}
if (DB::table('users')->count() !== $usersBefore) {
    throw new RuntimeException('System initialization must not create accounts.');
}
echo json_encode(['roles' => DB::table('roles')->count(), 'permissions' => DB::table('permissions')->count(), 'users' => $usersBefore], JSON_PRETTY_PRINT).PHP_EOL;
