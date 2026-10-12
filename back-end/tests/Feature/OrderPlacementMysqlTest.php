<?php

namespace Tests\Feature;

use App\Actions\Branches\ReplaceBusinessHoursAction;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPlacementMysqlTest extends TestCase
{
    public function test_legacy_placement_is_repaired_and_stays_visible_after_order_updates(): void
    {
        if (getenv('DRIVER_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Enable the existing isolated MySQL suite to verify placement timestamps.');
        }
        $database = 'shishli_placement_test_'.bin2hex(random_bytes(8));
        $this->assertMatchesRegularExpression('/^shishli_placement_test_[a-f0-9]{16}$/D', $database);
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $server = new \PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $original = DB::getDefaultConnection();
        try {
            config(['database.connections.placement_test' => $connection]);
            DB::setDefaultConnection('placement_test');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'placement_test', '--force' => true, '--no-interaction' => true]));
            $this->seed(RolePermissionSeeder::class);
            $this->travelTo(CarbonImmutable::parse('2026-10-08 21:01:37', 'UTC'));
            $branch = Branch::factory()->withoutBusinessHours()->create();
            app(ReplaceBusinessHoursAction::class)->handle($branch, collect(range(1, 7))->map(fn ($day) => ['day_of_week' => $day, 'opens_at' => '12:00', 'closes_at' => '02:00'])->all());
            $cart = Cart::factory()->forBranch($branch)->create();
            $order = Order::factory()->for($branch)->create(['cart_id' => $cart->id, 'status' => 'pending']);
            $independent = Order::factory()->for($branch)->create(['placed_at' => now()->subDay()]);
            DB::statement('ALTER TABLE orders MODIFY placed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
            DB::table('orders')->where('id', $order->id)->update(['placed_at' => now()->addHours(2)]);
            $this->actingAs(User::factory()->create()->assignRole('super_admin'), 'sanctum');
            $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(0, 'data');
            $migration = require database_path('migrations/2026_10_08_210656_stabilize_order_placement_timestamp.php');
            $migration->up();
            $this->assertTrue($order->fresh()->placed_at->equalTo(now()));
            $this->assertTrue($independent->fresh()->placed_at->equalTo(now()->subDay()));
            $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->uuid);
            $this->getJson('/api/v1/admin/orders/counts')->assertOk()->assertJsonPath('data.pending', 1);
            $this->getJson('/api/v1/admin/orders?period=history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $independent->uuid);
            $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertOk();
            $this->assertTrue($order->fresh()->placed_at->equalTo(now()));
            $this->getJson('/api/v1/admin/kitchen/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->uuid);
            $fresh = Order::factory()->for($branch)->create();
            $this->travel(1)->minutes();
            $fresh->update(['customer_notes' => 'Updated notes']);
            $this->assertTrue($fresh->fresh()->placed_at->equalTo(now()->subMinute()));
        } finally {
            DB::purge('placement_test');
            DB::setDefaultConnection($original);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }
}
