<?php

use App\Models\Branch;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql' || filled(config('database.connections.mysql.url')) || config('database.connections.mysql.host') !== 'mysql-test' || config('database.connections.mysql.database') !== 'shishli_e2e') {
    throw new RuntimeException('Refusing fixtures outside isolated Docker E2E database.');
}
$connection = config('database.connections.mysql');
$server = new PDO('mysql:host=mysql-test;port=3306', $connection['username'], $connection['password']);
$server->exec('CREATE DATABASE IF NOT EXISTS shishli_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true, '--no-interaction' => true]);
Page::query()->update(['status' => 'published', 'published_at' => now()->subDay()]);
$branch = Branch::where('slug', 'al-masif')->firstOrFail();
$customer = User::where('email', 'customer1@demo.shishli.local')->firstOrFail();
$customer->addresses()->update(['city' => 'تبوك', 'district' => 'المصيف', 'latitude' => $branch->latitude, 'longitude' => $branch->longitude]);
echo "ISOLATED_E2E_FIXTURE_READY\n";
