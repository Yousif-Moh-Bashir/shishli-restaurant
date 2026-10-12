<?php

namespace Tests\Feature;

use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CouponCheckoutConcurrencyTest extends TestCase
{
    public static function checkoutRaces(): array
    {
        return ['global limit' => [false], 'first order for same customer' => [true]];
    }

    #[DataProvider('checkoutRaces')]
    public function test_two_branches_cannot_exceed_shared_coupon_usage_limit(bool $firstOrderOnly): void
    {
        if (getenv('DRIVER_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set DRIVER_MYSQL_CONCURRENCY=1 to run isolated MySQL row-lock tests.');
        }
        $database = 'shishli_coupon_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_coupon_test_[a-f0-9]{16}$/D', $database);
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $server = new \PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();
        $processes = [];
        try {
            config(['database.connections.coupon_race' => $connection]);
            DB::setDefaultConnection('coupon_race');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'coupon_race', '--force' => true, '--no-interaction' => true]));
            $this->seed(RolePermissionSeeder::class);
            $promotion = Promotion::factory()->coupon()->create(['first_order_only' => $firstOrderOnly]);
            $coupon = Coupon::factory()->for($promotion)->create(['usage_limit' => $firstOrderOnly ? null : 1]);
            $user = $firstOrderOnly ? User::factory()->create() : null;
            $carts = [];
            foreach (range(1, 2) as $unused) {
                $assignment = BranchProduct::factory()->create(['price_override' => '100.00']);
                $cart = Cart::factory()->create(['branch_id' => $assignment->branch_id, 'coupon_id' => $coupon->id, 'user_id' => $user?->id]);
                CartItem::factory()->for($cart)->for($assignment->product)->create(['quantity' => 1, 'base_price' => '100.00', 'options_total' => '0.00', 'unit_price' => '100.00', 'line_total' => '100.00']);
                $carts[] = $cart;
            }
            $this->assertNotSame($carts[0]->branch_id, $carts[1]->branch_id);
            foreach ($carts as $cart) {
                $bootstrap = var_export(base_path(), true);
                $encoded = var_export(base64_encode(json_encode($connection, JSON_THROW_ON_ERROR)), true);
                $code = 'require '.$bootstrap.'."/vendor/autoload.php"; $app=require '.$bootstrap.'."/bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                    .'config(["database.connections.coupon_race"=>json_decode(base64_decode('.$encoded.'),true),"database.default"=>"coupon_race","cache.default"=>"array","queue.default"=>"sync"]); Illuminate\\Support\\Facades\\DB::setDefaultConnection("coupon_race");'
                    .'$cart=App\Models\Cart::findOrFail('.$cart->id.'); echo "READY\n"; fflush(STDOUT); fgets(STDIN);'
                    .'try { app(App\Actions\Orders\PlaceOrderAction::class)->handle($cart,["type"=>"pickup","payment_method"=>"cash","customer"=>["name"=>"QA customer","phone"=>"0551234567"]]); echo "201\n"; } catch(Illuminate\Http\Exceptions\HttpResponseException $e) { echo $e->getResponse()->getStatusCode()."\n"; } catch(Illuminate\Validation\ValidationException $e) { echo "422\n"; }';
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
            $this->assertSame(['201', '422'], $results);
            $this->assertSame(1, Order::count());
            $this->assertSame(1, $coupon->fresh()->used_count);
            $this->assertDatabaseCount('promotion_usages', 1);
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
            DB::purge('coupon_race');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
