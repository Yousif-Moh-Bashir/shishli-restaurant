<?php

namespace Tests\Feature;

use App\Models\BranchInventory;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    /** Opt-in integration test uses a newly created database; never migrates or mutates the configured application database. */
    public function test_two_mysql_checkout_processes_cannot_reserve_the_same_last_unit(): void
    {
        if (getenv('INVENTORY_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set INVENTORY_MYSQL_CONCURRENCY=1 to test real MySQL locking in an isolated temporary database.');
        }
        $database = 'shishli_inventory_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_inventory_test_[a-f0-9]{16}$/D', $database);
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $server = new \PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4',
            $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();
        $processes = [];
        try {
            config(['database.connections.inventory_concurrency' => $connection]);
            DB::setDefaultConnection('inventory_concurrency');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'inventory_concurrency', '--force' => true, '--no-interaction' => true]));
            $inventory = BranchInventory::factory()->create(['on_hand' => '1.000']);
            $carts = [];
            for ($index = 0; $index < 2; $index++) {
                $cart = Cart::factory()->for($inventory->branch)->create();
                CartItem::factory()->for($cart)->for($inventory->product)->create();
                $carts[] = $cart->id;
            }
            foreach ($carts as $cartId) {
                $bootstrap = var_export(base_path(), true);
                $encoded = var_export(base64_encode(json_encode($connection, JSON_THROW_ON_ERROR)), true);
                $code = 'require '.$bootstrap.'."/vendor/autoload.php"; $app = require '.$bootstrap.'."/bootstrap/app.php";'
                    .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                    .'config(["database.connections.inventory_concurrency"=>json_decode(base64_decode('.$encoded.'),true),'
                    .'"database.default"=>"inventory_concurrency","cache.default"=>"array","queue.default"=>"sync"]);'
                    .'Illuminate\\Support\\Facades\\DB::setDefaultConnection("inventory_concurrency");'
                    .'Illuminate\\Support\\Facades\\Event::fake([App\\Events\\OrderPlaced::class]);'
                    .'echo "READY\\n"; fflush(STDOUT); fgets(STDIN);'
                    .'try { $order = app(App\\Actions\\Orders\\PlaceOrderAction::class)->handle(App\\Models\\Cart::findOrFail('.$cartId.'),'
                    .'["type"=>"pickup","payment_method"=>"cash","customer"=>["name"=>"Concurrency","phone"=>"0501234567"]]); echo "WON\\n"; }'
                    .'catch (Illuminate\\Http\\Exceptions\\HttpResponseException $exception) { echo $exception->getResponse()->getStatusCode() === 422 ? "REJECTED\\n" : "ERROR\\n"; }';
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
            $this->assertSame(['REJECTED', 'WON'], $results);
            $this->assertSame(1, Order::count());
            $this->assertSame(1, StockReservation::count());
            $this->assertSame(1, StockMovement::where('type', 'reservation')->count());
            $this->assertSame('1.000', $inventory->fresh()->reserved);
            $this->assertSame('0.000', $inventory->fresh()->available());
            $this->assertSame(1, Cart::where('status', 'active')->count());
            try {
                DB::table('branch_inventories')->where('id', $inventory->id)->update(['reserved' => '2.000']);
                $this->fail('The MySQL CHECK constraint must reject reserved stock above on_hand.');
            } catch (QueryException) {
                $this->assertSame('1.000', $inventory->fresh()->reserved);
            }
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
            DB::purge('inventory_concurrency');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
