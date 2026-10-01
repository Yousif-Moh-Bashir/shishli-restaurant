<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_order_requires_matching_token_and_never_exposes_it(): void
    {
        $order = Order::factory()->create();
        $order->forceFill(['access_token' => str_repeat('a', 64)])->save();
        $other = Order::factory()->create();
        $other->forceFill(['access_token' => str_repeat('b', 64)])->save();
        $this->getJson('/api/v1/orders/'.$order->uuid.'/guest')->assertNotFound();
        $this->withHeader('X-Order-Token', str_repeat('x', 64))->getJson('/api/v1/orders/'.$order->uuid.'/guest')->assertNotFound();
        $this->withHeader('X-Order-Token', str_repeat('a', 64))->getJson('/api/v1/orders/'.$other->uuid.'/guest')->assertNotFound();
        $this->getJson('/api/v1/orders/'.$order->uuid.'/guest')->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)->assertJsonMissingPath('data.access_token');
        $this->assertArrayNotHasKey('access_token', $order->toArray());
    }

    public function test_authenticated_orders_require_owner_and_list_only_own_orders(): void
    {
        $user = User::factory()->create();
        $own = Order::factory()->for($user)->create();
        $other = Order::factory()->for(User::factory())->create();
        $guest = Order::factory()->create();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/orders/'.$own->uuid)->assertUnauthorized();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->uuid)->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.items')->assertJsonMissingPath('data.0.access_token');
        $this->getJson('/api/v1/orders/'.$own->uuid)->assertOk();
        $this->getJson('/api/v1/orders/'.$other->uuid)->assertNotFound();
        $this->getJson('/api/v1/orders/'.$guest->uuid)->assertNotFound();
        $this->getJson('/api/v1/orders/'.$own->uuid.'/guest')->assertNotFound();
    }

    public function test_admin_endpoints_require_permission_and_omit_tokens(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $order = Order::factory()->create();
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertForbidden();
        $user->givePermissionTo('orders.view');
        $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.access_token');
        $this->getJson('/api/v1/admin/orders/'.$order->uuid)->assertOk()->assertJsonMissingPath('data.access_token');
    }

    #[DataProvider('filters')]
    public function test_admin_filters_select_matching_orders(string $filter): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo('orders.view');
        $target = Order::factory()->confirmed()->create(['customer_name' => 'Target Customer', 'customer_phone' => '0501112223',
            'placed_at' => '2026-09-15 12:00:00', 'payment_status' => 'paid']);
        Order::factory()->delivery()->create(['customer_name' => 'Other', 'customer_phone' => '0509999999', 'placed_at' => '2026-09-01 12:00:00']);
        $query = match ($filter) {
            'number' => ['search' => $target->order_number],
            'name' => ['search' => 'Target'],
            'phone' => ['search' => '050111'],
            'branch' => ['branch' => $target->branch->uuid],
            'status' => ['status' => 'confirmed'],
            'type' => ['type' => 'pickup'],
            'payment' => ['payment_status' => 'paid'],
            'from' => ['date_from' => '2026-09-15'],
            'range' => ['date_from' => '2026-09-15', 'date_to' => '2026-09-15'],
        };
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/orders?'.http_build_query($query))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->uuid);
    }

    public static function filters(): array
    {
        return array_map(fn (string $value): array => [$value], ['number', 'name', 'phone', 'branch', 'status', 'type', 'payment', 'from', 'range']);
    }

    public function test_admin_date_to_alone_pagination_and_filter_validation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo('orders.view');
        Order::factory()->count(3)->create(['placed_at' => '2026-09-15 12:00:00']);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/orders?date_to=2026-09-15&per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/admin/orders?status=invalid&per_page=101&branch=bad')->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'per_page', 'branch']);
        $this->getJson('/api/v1/admin/orders?date_from=2026-09-16&date_to=2026-09-15')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_order_show_query_count_does_not_grow_with_items_and_options(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        OrderItem::factory()->for($order)->hasOptions(2)->create();
        $this->actingAs($user, 'sanctum');
        DB::enableQueryLog();
        $this->getJson('/api/v1/orders/'.$order->uuid)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        OrderItem::factory()->count(5)->for($order)->hasOptions(2)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/orders/'.$order->uuid)->assertOk()->assertJsonCount(6, 'data.items');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small, $large);
    }

    public function test_hard_deleted_product_does_not_remove_order_item_history(): void
    {
        $product = Product::factory()->create();
        $item = OrderItem::factory()->for($product)->create(['product_uuid' => $product->uuid, 'product_name' => $product->name]);
        $product->forceDelete();
        $this->assertNull($item->fresh()->product_id);
        $this->assertSame($product->uuid, $item->fresh()->product_uuid);
        $this->assertSame($product->name, $item->fresh()->product_name);
    }
}
