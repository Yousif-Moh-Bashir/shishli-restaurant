<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Events\OrderPlaced;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_order_event_is_dispatched_only_after_outer_transaction_commits(): void
    {
        $cart = $this->cart();
        $events = [];
        Event::listen(OrderPlaced::class, function (OrderPlaced $event) use (&$events): void {
            $events[] = [$event->order->uuid, DB::transactionLevel(), $event->order->cart->status];
        });
        DB::beginTransaction();
        $response = $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        $this->assertSame([], $events);
        DB::commit();
        $this->assertSame([[$response->json('data.id'), 0, CartStatus::Converted]], $events);
        $this->postJson('/api/v1/checkout', $this->payload())->assertOk();
        $this->assertCount(1, $events);
    }

    public function test_outer_rollback_discards_order_conversion_and_event(): void
    {
        $cart = $this->cart();
        $events = [];
        Event::listen(OrderPlaced::class, function (OrderPlaced $event) use (&$events): void {
            $events[] = $event;
        });
        DB::beginTransaction();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        DB::rollBack();
        $this->assertSame([], $events);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
    }

    public function test_snapshot_write_failure_rolls_back_order_and_cart_repricing(): void
    {
        $cart = $this->cart();
        $cart->items()->first()->product->update(['base_price' => '50.00']);
        Event::fake([OrderPlaced::class]);
        $event = 'eloquent.creating: '.OrderItem::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated snapshot persistence failure');
        });
        try {
            $this->postJson('/api/v1/checkout', $this->payload())->assertServerError();
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_item_options', 0);
        $this->assertDatabaseCount('order_addresses', 0);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
        $this->assertSame('20.00', $cart->fresh()->subtotal);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_database_rejects_a_second_order_for_the_same_cart(): void
    {
        $cart = $this->cart();
        $this->postJson('/api/v1/checkout', $this->payload())->assertCreated();
        try {
            Order::factory()->create(['cart_id' => $cart->id]);
            $this->fail('A second order must violate the unique cart constraint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders.cart_id', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_initial_history_failure_rolls_back_checkout_and_does_not_dispatch_order_event(): void
    {
        $cart = $this->cart();
        Event::fake([OrderPlaced::class]);
        $event = 'eloquent.creating: '.OrderStatusHistory::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated initial history persistence failure');
        });

        try {
            $this->postJson('/api/v1/checkout', $this->payload())->assertServerError();
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_item_options', 0);
        $this->assertDatabaseCount('order_addresses', 0);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    private function cart(): Cart
    {
        $assignment = BranchProduct::factory()->create(['price_override' => '20.00']);
        $response = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $response->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();
        $assignment->update(['price_override' => null]);
        $assignment->product->update(['base_price' => '20.00']);

        return Cart::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    private function payload(): array
    {
        return ['type' => 'pickup', 'customer' => ['name' => 'Customer', 'phone' => '0501234567'], 'payment_method' => 'cash'];
    }
}
