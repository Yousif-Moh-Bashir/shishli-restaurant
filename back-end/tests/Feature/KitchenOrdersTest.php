<?php

namespace Tests\Feature;

use App\Enums\OrderStatusSource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KitchenOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_queue_is_oldest_first_and_uses_minimal_snapshot_data(): void
    {
        $kitchen = $this->staff('kitchen');
        $old = Order::factory()->confirmed()->create(['placed_at' => '2026-09-01 10:00:00', 'customer_notes' => 'No onions']);
        $item = OrderItem::factory()->for($old)->hasOptions(1)->create(['product_name' => 'Snapshot chicken']);
        $new = Order::factory()->create(['status' => 'preparing', 'placed_at' => '2026-09-01 11:00:00']);
        foreach (['pending', 'ready', 'completed', 'cancelled', 'out_for_delivery'] as $status) {
            Order::factory()->create(['status' => $status]);
        }
        $this->getJson('/api/v1/kitchen/orders')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/kitchen/orders')->assertForbidden();
        $this->actingAs($kitchen, 'sanctum')->getJson('/api/v1/kitchen/orders')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $old->uuid)->assertJsonPath('data.1.id', $new->uuid)
            ->assertJsonPath('data.0.items.0.product.name', 'Snapshot chicken')->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('data.0.customer_notes', 'No onions')->assertJsonCount(1, 'data.0.items.0.options')
            ->assertJsonMissingPath('data.0.customer')->assertJsonMissingPath('data.0.payment')
            ->assertJsonMissingPath('data.0.address')->assertJsonMissingPath('data.0.access_token')
            ->assertJsonMissingPath('data.0.items.0.pricing')->assertJsonMissingPath('data.0.history');
    }

    public function test_kitchen_filters_status_type_branch_and_pagination(): void
    {
        $kitchen = $this->staff('kitchen');
        $branch = Branch::factory()->create();
        $match = Order::factory()->for($branch)->create(['status' => 'ready', 'type' => 'delivery']);
        Order::factory()->for($branch)->create(['status' => 'ready', 'type' => 'pickup']);
        Order::factory()->create(['status' => 'ready', 'type' => 'delivery']);
        $this->actingAs($kitchen, 'sanctum')->getJson('/api/v1/kitchen/orders?'.http_build_query([
            'status' => 'ready', 'type' => 'delivery', 'branch' => $branch->uuid, 'per_page' => 1,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->uuid)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/kitchen/orders?status=completed&type=invalid&branch=bad&per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'type', 'branch', 'per_page']);
    }

    #[DataProvider('staffCommands')]
    public function test_role_boundaries_and_sources(string $role, string $command, string $type, string $from, int $expected): void
    {
        $actor = $this->staff($role);
        $order = Order::factory()->create(['type' => $type, 'status' => $from]);
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/'.$command, ['reason' => 'Cancel request'])
            ->assertStatus($expected);
        if ($expected === 200) {
            $history = $order->statusHistory()->sole();
            $this->assertSame($actor->id, $history->changed_by);
            $this->assertSame($role === 'kitchen' ? OrderStatusSource::Kitchen : OrderStatusSource::Cashier, $history->source);
        } else {
            $this->assertSame($from, $order->fresh()->status->value);
            $this->assertDatabaseCount('order_status_histories', 0);
        }
    }

    public static function staffCommands(): array
    {
        return [
            ['kitchen', 'start-preparing', 'pickup', 'confirmed', 200],
            ['kitchen', 'mark-ready', 'delivery', 'preparing', 200],
            ['kitchen', 'confirm', 'pickup', 'pending', 403],
            ['kitchen', 'dispatch', 'delivery', 'ready', 403],
            ['kitchen', 'complete', 'delivery', 'out_for_delivery', 403],
            ['kitchen', 'cancel', 'pickup', 'pending', 403],
            ['cashier', 'confirm', 'delivery', 'pending', 200],
            ['cashier', 'complete', 'pickup', 'ready', 200],
            ['cashier', 'complete', 'delivery', 'out_for_delivery', 403],
            ['cashier', 'start-preparing', 'pickup', 'confirmed', 403],
            ['cashier', 'mark-ready', 'pickup', 'preparing', 403],
            ['cashier', 'dispatch', 'delivery', 'ready', 403],
            ['cashier', 'cancel', 'pickup', 'pending', 403],
        ];
    }

    public function test_kitchen_extra_explicit_permission_is_respected(): void
    {
        $kitchen = $this->staff('kitchen')->givePermissionTo('orders.confirm');
        $order = Order::factory()->create();
        $this->actingAs($kitchen, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm')->assertOk();
        $this->assertSame(OrderStatusSource::Kitchen, $order->statusHistory()->sole()->source);
    }

    public function test_queue_eager_loading_has_bounded_queries(): void
    {
        $kitchen = $this->staff('kitchen');
        Order::factory()->confirmed()->has(OrderItem::factory()->hasOptions(2), 'items')->create();
        $this->actingAs($kitchen, 'sanctum')->getJson('/api/v1/kitchen/orders')->assertOk();
        DB::enableQueryLog();
        $this->getJson('/api/v1/kitchen/orders')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        Order::factory()->count(5)->confirmed()->has(OrderItem::factory()->count(3)->hasOptions(2), 'items')->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/kitchen/orders')->assertOk()->assertJsonCount(6, 'data');
        $large = count(DB::getQueryLog());
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small, $large);
        $this->assertStringNotContainsString('order_addresses', $queries);
        $this->assertStringNotContainsString('order_status_histories', $queries);
    }

    private function staff(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole($role);
    }
}
