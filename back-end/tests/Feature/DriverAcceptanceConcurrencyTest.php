<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriverAcceptanceConcurrencyTest extends TestCase
{
    #[DataProvider('races')]
    public function test_concurrent_acceptance_has_exactly_one_winner(bool $sameDriver): void
    {
        if (getenv('DRIVER_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set DRIVER_MYSQL_CONCURRENCY=1 to run isolated MySQL row-lock tests.');
        }
        $database = 'shishli_driver_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_driver_test_[a-f0-9]{16}$/D', $database);
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $server = new \PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();
        $processes = [];
        try {
            config(['database.connections.driver_concurrency' => $connection]);
            DB::setDefaultConnection('driver_concurrency');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'driver_concurrency', '--force' => true, '--no-interaction' => true]));
            $this->seed(RolePermissionSeeder::class);
            $branch = Branch::factory()->create();
            $drivers = User::factory()->count($sameDriver ? 1 : 2)->create();
            foreach ($drivers as $driver) {
                $driver->assignRole('driver');
                $driver->forceFill(['driver_branch_id' => $branch->id, 'driver_available' => true, 'driver_enabled' => true])->save();
            }
            $orders = Order::factory()->delivery()->count($sameDriver ? 2 : 1)->create(['branch_id' => $branch->id, 'status' => 'ready']);
            for ($index = 0; $index < 2; $index++) {
                $driverId = $drivers[$sameDriver ? 0 : $index]->id;
                $orderId = $orders[$sameDriver ? $index : 0]->id;
                $bootstrap = var_export(base_path(), true);
                $encoded = var_export(base64_encode(json_encode($connection, JSON_THROW_ON_ERROR)), true);
                $code = 'require '.$bootstrap.'."/vendor/autoload.php"; $app=require '.$bootstrap.'."/bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                    .'config(["database.connections.driver_concurrency"=>json_decode(base64_decode('.$encoded.'),true),"database.default"=>"driver_concurrency","cache.default"=>"array","queue.default"=>"sync"]); Illuminate\\Support\\Facades\\DB::setDefaultConnection("driver_concurrency");'
                    .'$driver=App\\Models\\User::findOrFail('.$driverId.'); $order=App\\Models\\Order::findOrFail('.$orderId.'); echo "READY\\n"; fflush(STDOUT); fgets(STDIN);'
                    .'try { app(App\\Actions\\Drivers\\AcceptDeliveryOrderAction::class)->handle($driver,$order); echo "200\\n"; } catch(Symfony\\Component\\HttpKernel\\Exception\\HttpException $e) { echo $e->getStatusCode()."\\n"; }';
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 30);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
            }
            foreach ($processes as $process) {
                $this->assertSame("READY\n", fgets($process['pipes'][1]));
            }
            foreach ($processes as $process) {
                fwrite($process['pipes'][0], "GO\n");
                fflush($process['pipes'][0]);
            }
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
            $this->assertSame(['200', '409'], $results);
            $this->assertSame(1, Order::whereNotNull('driver_id')->count());
            $this->assertDatabaseCount('order_delivery_histories', 1);
        } finally {
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
            DB::purge('driver_concurrency');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }

    public static function races(): array
    {
        return ['two drivers one order' => [false], 'one driver two orders' => [true]];
    }
}
