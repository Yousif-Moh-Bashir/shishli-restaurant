<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== 'finance-mysql'
    || config('database.connections.mysql.database') !== 'shishli_finance_test'
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('Refusing non-isolated runtime database.');
}
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Test migrations failed.');
}
Artisan::call('db:seed', ['--class' => FinanceSeeder::class, '--force' => true, '--no-interaction' => true]);
Artisan::call('db:seed', ['--class' => FinanceSeeder::class, '--force' => true, '--no-interaction' => true]);
Branch::firstOrCreate(['slug' => 'finance-runtime-only'], ['name' => 'فرع اختبار مالي معزول', 'is_active' => true]);
$user = User::firstOrCreate(['email' => 'finance-runtime@example.test'], ['name' => 'مدير الاختبار المالي', 'password' => 'RuntimeFixturePassword123']);
$user->assignRole('finance_manager');
echo "Isolated runtime fixture ready; finance seed executed twice.\n";
