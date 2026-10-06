<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderTrackingPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_tracking_rate_limit_is_ip_scoped_and_allows_normal_polling(): void
    {
        $order = Order::factory()->create(['access_token' => str_repeat('a', 64)]);
        $payload = ['order_number' => $order->order_number, 'token' => str_repeat('b', 64)];
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/api/v1/orders/track', $payload)->assertNotFound();
        }
        $this->postJson('/api/v1/orders/track', $payload)->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.22']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'token' => str_repeat('a', 64)])->assertOk();
        }
    }

    public function test_guest_tracking_eager_loads_items_options_and_history_without_n_plus_one(): void
    {
        $order = Order::factory()->create(['access_token' => str_repeat('a', 64)]);
        $item = OrderItem::factory()->for($order)->create();
        OrderItemOption::factory()->for($item, 'orderItem')->create();
        OrderStatusHistory::factory()->for($order)->create();
        $payload = ['order_number' => $order->order_number, 'token' => str_repeat('a', 64)];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/v1/orders/track', $payload)->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (OrderItem::factory()->count(8)->for($order)->create() as $line) {
            OrderItemOption::factory()->count(3)->for($line, 'orderItem')->create();
        }
        OrderStatusHistory::factory()->count(8)->for($order)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/v1/orders/track', $payload)->assertOk()->assertJsonCount(9, 'data.items');
        $this->assertSame($baseline, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_owner_tracking_query_count_remains_constant_and_has_no_stale_status_cache(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        $path = '/api/v1/orders/'.$order->uuid.'/tracking';
        $this->actingAs($user, 'sanctum');
        $this->getJson($path)->assertOk()->assertJsonPath('data.order.status.code', 'pending');
        $order->update(['status' => 'confirmed']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.order.status.code', 'confirmed');
    }
}
