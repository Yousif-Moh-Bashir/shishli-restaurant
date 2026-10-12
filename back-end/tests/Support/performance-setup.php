<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Explicitly requested audit harness. Only creates uniquely named synthetic databases.
$driver = $argv[1] ?? 'mysql';
if (! in_array($driver, ['mysql', 'sqlite'], true)) {
    throw new RuntimeException('Unsupported audit database');
}
$enrich = ($argv[2] ?? '') === '--enrich';
$output = __DIR__.'/../../../front-end/.performance-check/audit-'.$driver.'.json';
$existing = $enrich ? json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR) : null;
$database = $existing['database'] ?? ($driver === 'mysql' ? 'shishli_perf_'.bin2hex(random_bytes(6)) : __DIR__.'/../../storage/framework/testing/performance-audit.sqlite');
if ($driver === 'mysql' && ! preg_match('/^shishli_perf_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Refusing non-audit database');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing audit bootstrap with cached application configuration');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $enrich && $driver === 'mysql') {
    $connection = config('database.connections.mysql');
    $pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} elseif (! $enrich) {
    if (file_exists($database)) {
        throw new RuntimeException('Use a fresh SQLite fixture, never overwrite an existing database');
    }
    @mkdir(dirname($database), 0777, true);
    touch($database);
}
if (! $enrich) {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true, '--no-interaction' => true]);
}
Page::query()->update(['status' => 'published', 'published_at' => now()->subDay()]);
if ($enrich) {
    echo "Published synthetic CMS pages\n";
    exit(0);
}
$branch = Branch::where('slug', 'al-masif')->firstOrFail();
$customer = User::where('email', 'customer1@demo.shishli.local')->firstOrFail();
$admin = User::where('email', 'super_admin@demo.shishli.local')->firstOrFail();
$driverUser = User::factory()->create(['name' => 'Performance driver', 'email' => 'perf.driver@example.test', 'phone' => '+966551234599', 'password' => 'DemoTest123!']);
$driverUser->assignRole('driver');
$driverUser->forceFill(['driver_branch_id' => $branch->id, 'driver_enabled' => true, 'driver_available' => true])->save();
$product = Product::where('slug', 'half-turkish-chicken')->firstOrFail();
$customer->addresses()->update(['city' => 'تبوك', 'district' => 'المصيف', 'latitude' => $branch->latitude, 'longitude' => $branch->longitude]);
$order = Order::where('user_id', $customer->id)->firstOrFail();
$fixture = ['driver' => $driver, 'database' => $database, 'branch' => $branch->uuid, 'product' => $product->uuid, 'order' => $order->uuid,
    'tokens' => ['customer' => $customer->createToken('performance-audit')->plainTextToken, 'admin' => $admin->createToken('performance-audit')->plainTextToken, 'driver' => $driverUser->createToken('performance-audit')->plainTextToken],
    'counts' => ['products' => Product::count(), 'orders' => Order::count(), 'users' => User::count()]];
$output = __DIR__.'/../../../front-end/.performance-check/audit-'.$driver.'.json';
file_put_contents($output, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['driver' => $driver, 'database' => $database, 'counts' => $fixture['counts']], JSON_PRETTY_PRINT).PHP_EOL;
