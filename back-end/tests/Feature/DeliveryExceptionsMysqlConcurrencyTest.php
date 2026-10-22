<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Drivers\DriverOrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryExceptionsMysqlConcurrencyTest extends TestCase
{
    #[Group('delivery-mysql')]
    #[DataProvider('scenarios')]
    public function test_mysql_84_migration_and_concurrent_delivery_operations(string $scenario): void
    {
        if (getenv('DELIVERY_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Run with the standalone delivery release test Compose file.');
        }
        $connection = config('database.connections.mysql');
        $this->assertSame('testing', app()->environment());
        $this->assertSame('delivery-mysql-test', $connection['host']);
        $this->assertSame('shishli_delivery_release_test', $connection['database']);
        $this->assertEmpty($connection['url']);
        $server = new \PDO('mysql:host=delivery-mysql-test;port=3306', $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->assertStringStartsWith('8.4.', (string) $server->query('SELECT VERSION()')->fetchColumn());
        $database = 'shishli_delivery_race_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_delivery_race_[a-f0-9]{16}$/D', $database);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();
        $processes = [];
        try {
            $connection['database'] = $database;
            config(['database.connections.delivery_race' => $connection]);
            DB::setDefaultConnection('delivery_race');
            $migrationPath = 'database/migrations/2026_10_21_201116_add_delivery_exception_management.php';
            $options = ['--database' => 'delivery_race', '--force' => true, '--no-interaction' => true];
            if ($scenario === 'upgrade') {
                $options['--path'] = array_values(array_filter(array_map(fn (string $path): string => 'database/migrations/'.basename($path), glob(database_path('migrations/*.php'))), fn (string $path): bool => $path !== $migrationPath));
            }
            $this->assertSame(0, Artisan::call('migrate', $options));
            $this->seed(RolePermissionSeeder::class);
            $branch = Branch::factory()->create();
            $drivers = User::factory()->count(3)->create();
            foreach ($drivers as $driver) {
                $driver->assignRole('driver');
                $driver->forceFill(['driver_branch_id' => $branch->id, 'driver_available' => true, 'driver_enabled' => true])->save();
            }
            $admin = User::factory()->create()->assignRole('super_admin');
            $order = Order::factory()->delivery()->create(['branch_id' => $branch->id, 'driver_id' => $drivers[0]->id,
                'status' => 'out_for_delivery', 'delivery_status' => $scenario === 'confirm_return' ? 'returning' : 'on_the_way',
                'payment_status' => $scenario === 'partial_upgrade' ? 'pending' : 'paid', 'payment_method' => 'network', 'total' => '57.00', 'delivery_picked_up_at' => now()]);
            if ($scenario === 'partial_upgrade') {
                $role = Role::findByName('manager', 'web');
                $role->permissions_customized = true;
                $role->save();
                $role->syncPermissions(['orders.view']);
                $before = $order->fresh()->getRawOriginal();
                $migration = require base_path($migrationPath);
                $migration->down();
                Schema::table('orders', fn ($table) => $table->unsignedInteger('delivery_revision')->default(0));
                $migration->up();
                $migration->up();
                $this->assertSame($before['payment_status'], $order->fresh()->payment_status->value);
                $this->assertSame($before['delivery_status'], $order->fresh()->delivery_status->value);
                $this->assertSame(['orders.view'], $role->fresh()->permissions->pluck('name')->all());
                $this->assertSame(6, Permission::where('name', 'like', 'delivery_exceptions.%')->count());
                $this->assertFalse(User::factory()->create()->assignRole('manager')->can('delivery_exceptions.resolve_custody'));

                return;
            }
            $payment = Payment::factory()->paid()->create(['order_id' => $order->id, 'method' => 'network', 'amount' => '57.00']);
            $sale = PaymentTransaction::factory()->sale()->succeeded()->create(['payment_id' => $payment->id, 'operation_key' => 'sale']);
            $paymentBefore = $payment->fresh()->getRawOriginal();
            $saleBefore = $sale->fresh()->getRawOriginal();
            if ($scenario === 'upgrade') {
                $completed = Order::factory()->delivery()->create(['status' => 'completed', 'delivery_status' => 'delivered', 'payment_status' => 'paid']);
                $before = $order->fresh()->getRawOriginal();
                Schema::table('orders', fn ($table) => $table->unsignedInteger('delivery_revision')->default(0));
                $this->assertSame(0, Artisan::call('migrate', ['--path' => [$migrationPath]] + $options));
                $migration = require base_path($migrationPath);
                $migration->up();
                $after = $order->fresh()->getRawOriginal();
                $this->assertSame($before, array_intersect_key($after, $before));
                $this->assertSame(0, $order->fresh()->delivery_revision);
                $this->assertNull($order->fresh()->delivery_task_closed_at);
                $this->assertSame('completed', $completed->fresh()->status->value);
                $this->assertSame('paid', $completed->fresh()->payment_status->value);
                $this->assertSame('text', Schema::getColumnType('admin_activity_logs', 'description'));
                $manager = User::factory()->create()->assignRole('manager');
                $this->assertFalse($manager->can('delivery_exceptions.resolve_custody'));
                $this->assertTrue($admin->can('delivery_exceptions.resolve_custody'));
                $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
                $this->assertSame($saleBefore, $sale->fresh()->getRawOriginal());

                return;
            }
            $key = (string) Str::uuid();
            $payload = ['action' => 'fail', 'reason' => 'العميل غير متاح في اختبار معزول', 'expected_revision' => 0, 'operation_key' => $key, 'acknowledged' => true];
            $jobs = array_fill(0, 2, ['kind' => 'exception', 'actor' => $admin->id, 'order' => $order->id, 'payload' => $payload]);
            $expected = ['200', '409'];
            if ($scenario === 'same_key') {
                $expected = ['200', '200'];
            } elseif ($scenario === 'different_keys') {
                $jobs[1]['payload']['operation_key'] = (string) Str::uuid();
            } elseif ($scenario === 'different_intent') {
                $jobs[1]['payload']['reason'] = 'سبب مختلف بنفس المفتاح';
            } elseif ($scenario === 'cross_order_key') {
                $second = Order::factory()->delivery()->create(['branch_id' => $branch->id, 'driver_id' => $drivers[1]->id, 'status' => 'out_for_delivery', 'delivery_status' => 'on_the_way']);
                $jobs[1]['order'] = $second->id;
            } elseif ($scenario === 'deliver_return') {
                $jobs[0] = ['kind' => 'deliver', 'actor' => $drivers[0]->id, 'order' => $order->id];
                $jobs[1]['payload']['action'] = 'start_return';
            } elseif (in_array($scenario, ['reassign_accept', 'same_reassign'], true)) {
                foreach ($jobs as &$job) {
                    $job['payload']['action'] = 'reassign';
                    $job['payload']['driver_uuid'] = $drivers[1]->uuid;
                }
                unset($job);
                if ($scenario === 'same_reassign') {
                    $expected = ['200', '200'];
                } else {
                    $next = Order::factory()->delivery()->create(['branch_id' => $branch->id, 'status' => 'ready', 'placed_at' => now()]);
                    $jobs[1] = ['kind' => 'accept', 'actor' => $drivers[1]->id, 'order' => $next->id];
                }
            } elseif ($scenario === 'confirm_return') {
                foreach ($jobs as &$job) {
                    $job['payload']['action'] = 'confirm_return';
                }
                unset($job);
                $expected = ['200', '200'];
            }
            DB::beginTransaction();
            User::whereIn('id', $drivers->modelKeys())->orderBy('id')->lockForUpdate()->get();
            foreach ($jobs as $job) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/delivery-race-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 30);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
                fwrite($pipes[0], json_encode(['database' => $database] + $job, JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
            }
            foreach ($processes as $process) {
                $this->assertSame("READY\n", fgets($process['pipes'][1]));
                fwrite($process['pipes'][0], "GO\n");
                fflush($process['pipes'][0]);
            }
            foreach ($processes as $process) {
                $this->assertSame("START\n", fgets($process['pipes'][1]));
            }
            $read = array_column(array_column($processes, 'pipes'), 1);
            $write = $except = null;
            $this->assertSame(0, stream_select($read, $write, $except, 0, 200000), 'Workers must wait for the held driver row locks.');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $results[] = trim((string) fgets($process['pipes'][1]));
                fclose($process['pipes'][0]);
                $errors = stream_get_contents($process['pipes'][2]);
                fclose($process['pipes'][1]);
                fclose($process['pipes'][2]);
                $this->assertSame(0, proc_close($process['process']), $errors);
            }
            $processes = [];
            sort($results);
            $this->assertSame($expected, $results);
            $this->assertSame('paid', $order->fresh()->payment_status->value);
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
            $this->assertSame($saleBefore, $sale->fresh()->getRawOriginal());
            foreach (DB::table('admin_activity_logs')->select('action')->selectRaw('COUNT(*) AS total')->groupBy('action')->get() as $audit) {
                $this->assertSame(1, (int) $audit->total);
            }
            $this->assertDatabaseCount('order_delivery_histories', 1);
            if (! in_array($scenario, ['deliver_return', 'reassign_accept'], true)) {
                $this->assertDatabaseCount('delivery_operations', 1);
            }
            $targetActive = app(DriverOrderService::class)->active($drivers[1])->count();
            $this->assertLessThanOrEqual(1, $targetActive);
            if ($scenario === 'confirm_return') {
                $this->assertSame('returned', $order->fresh()->delivery_status->value);
                $this->assertNotNull($order->fresh()->delivery_task_closed_at);
                $this->assertNull($order->fresh()->delivery_delivered_at);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if (is_resource($process['process'])) {
                    proc_terminate($process['process']);
                }
                foreach ($process['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process['process'])) {
                    proc_close($process['process']);
                }
            }
            DB::purge('delivery_race');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }

    public static function scenarios(): array
    {
        return array_map(fn (string $scenario): array => [$scenario], ['same_key', 'different_keys', 'different_intent',
            'cross_order_key', 'deliver_return', 'reassign_accept', 'same_reassign', 'confirm_return', 'upgrade', 'partial_upgrade']);
    }
}
