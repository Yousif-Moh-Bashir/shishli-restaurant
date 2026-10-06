<?php

namespace Tests\Feature;

use App\Actions\Inventory\ConsumeOrderStockAction;
use App\Actions\Inventory\ReleaseOrderStockAction;
use App\Actions\Inventory\ReserveOrderStockAction;
use App\Actions\Inventory\ReturnOrderStockAction;
use App\Actions\Orders\PlaceOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\StockReservationStatus;
use App\Events\LowStockDetected;
use App\Events\OrderPlaced;
use App\Events\StockRecovered;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryOrderIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(string $balance = '10.000', int $quantity = 2): array
    {
        $inventory = BranchInventory::factory()->create(['on_hand' => $balance]);
        $cart = Cart::factory()->for($inventory->branch)->create();
        CartItem::factory()->for($cart)->for($inventory->product)->create(['quantity' => $quantity, 'line_total' => (string) ($quantity * 20)]);

        return [$cart, $inventory];
    }

    private function payload(): array
    {
        return ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'عميل', 'phone' => '0501234567']];
    }

    private function place(Cart $cart): Order
    {
        return app(PlaceOrderAction::class)->handle($cart, $this->payload());
    }

    private function actor(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('manager');
    }

    private function transition(Order $order, OrderStatus $status, User $actor): Order
    {
        return app(TransitionOrderStatusAction::class)->handle($order, $status, $actor, OrderStatusSource::Admin, $status === OrderStatus::Cancelled ? 'إلغاء الطلب' : null);
    }

    public function test_checkout_reserves_and_retry_never_double_reserves(): void
    {
        [$cart,$inventory] = $this->fixture();
        Event::fake([OrderPlaced::class]);
        $order = $this->place($cart);
        $this->assertSame('10.000', $inventory->fresh()->on_hand);
        $this->assertSame('2.000', $inventory->fresh()->reserved);
        $this->assertSame('8.000', $inventory->fresh()->available());
        $this->assertSame(StockReservationStatus::Active, $order->stockReservations()->first()->status);
        $this->assertSame($order->id, $this->place($cart)->id);
        app(ReserveOrderStockAction::class)->handle($order);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('reservation', StockMovement::firstOrFail()->type->value);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_entering_preparing_consumes_once_and_all_later_statuses_leave_stock_unchanged(): void
    {
        [$cart,$inventory] = $this->fixture();
        $actor = $this->actor();
        $order = $this->transition($this->place($cart), OrderStatus::Confirmed, $actor);
        $this->assertSame('10.000', $inventory->fresh()->on_hand);
        $order = $this->transition($order, OrderStatus::Preparing, $actor);
        $this->assertSame('8.000', $inventory->fresh()->on_hand);
        $this->assertSame('0.000', $inventory->fresh()->reserved);
        $this->assertSame(StockReservationStatus::Consumed, $order->stockReservations()->first()->status);
        app(ConsumeOrderStockAction::class)->handle($order, $actor);
        $order = $this->transition($order, OrderStatus::Ready, $actor);
        $this->assertSame('8.000', $inventory->fresh()->on_hand);
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
        $this->assertNotNull($order->stockReservations()->first()->consumed_at);
    }

    #[DataProvider('cancellationStages')]
    public function test_cancellation_before_preparing_releases_and_after_preparing_does_not_return(string $stage, string $balance, string $reservationStatus): void
    {
        [$cart,$inventory] = $this->fixture();
        $actor = $this->actor();
        $order = $this->place($cart);
        if ($stage !== 'pending') {
            $order = $this->transition($order, OrderStatus::Confirmed, $actor);
        }
        if (in_array($stage, ['preparing', 'ready'], true)) {
            $order = $this->transition($order, OrderStatus::Preparing, $actor);
        }
        if ($stage === 'ready') {
            $order = $this->transition($order, OrderStatus::Ready, $actor);
        }
        $order = $this->transition($order, OrderStatus::Cancelled, $actor);
        app(ReleaseOrderStockAction::class)->handle($order, $actor);
        $this->assertSame($balance, $inventory->fresh()->on_hand);
        $this->assertSame('0.000', $inventory->fresh()->reserved);
        $this->assertSame($reservationStatus, $order->stockReservations()->first()->status->value);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $this->assertSame($reservationStatus === 'released' ? 1 : 0, StockMovement::where('type', 'reservation_release')->count());
    }

    public static function cancellationStages(): array
    {
        return [['pending', '10.000', 'released'], ['confirmed', '10.000', 'released'], ['preparing', '8.000', 'consumed'], ['ready', '8.000', 'consumed']];
    }

    public function test_explicit_cancelled_consumed_return_is_audited_once(): void
    {
        [$cart,$inventory] = $this->fixture();
        $actor = $this->actor();
        $order = $this->transition($this->place($cart), OrderStatus::Confirmed, $actor);
        $order = $this->transition($order, OrderStatus::Preparing, $actor);
        $order = $this->transition($order, OrderStatus::Cancelled, $actor);
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/stock/return', ['reason' => 'تم التأكد من إعادة الوحدات'])->assertOk();
        app(ReturnOrderStockAction::class)->handle($order, $actor, 'إعادة الوحدات');
        $this->assertSame('10.000', $inventory->fresh()->on_hand);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
        $movement = StockMovement::where('type', 'return')->firstOrFail();
        $this->assertSame($actor->id, $movement->created_by);
        $this->assertSame($order->uuid, $movement->reference_uuid);
        $this->assertSame(StockReservationStatus::Consumed, $order->stockReservations()->first()->status);
    }

    public function test_return_requires_permission_reason_and_cancelled_status(): void
    {
        [$cart] = $this->fixture();
        $actor = $this->actor();
        $order = $this->place($cart);
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/stock/return', [])->assertUnprocessable();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/stock/return', ['reason' => 'إرجاع'])->assertUnprocessable();
        $customer = User::factory()->create()->assignRole('customer');
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/stock/return', ['reason' => 'إرجاع'])->assertForbidden();
    }

    public function test_multi_product_reservation_failure_rolls_back_every_stock_row_and_ledger(): void
    {
        $branch = Branch::factory()->create();
        $first = BranchInventory::factory()->for($branch)->create(['on_hand' => '5.000']);
        $last = BranchInventory::factory()->for($branch)->create(['on_hand' => '1.000']);
        $order = Order::factory()->for($branch)->create();
        foreach ([$first, $last] as $inventory) {
            OrderItem::factory()->for($order)->create(['product_id' => $inventory->product_id, 'product_uuid' => $inventory->product->uuid, 'quantity' => 2]);
        }
        try {
            app(ReserveOrderStockAction::class)->handle($order);
            $this->fail('Stock must reject the second item.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
        }
        $this->assertSame('0.000', $first->fresh()->reserved);
        $this->assertSame('0.000', $last->fresh()->reserved);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_checkout_exception_after_reservation_rolls_back_order_payment_history_cart_and_ledger(): void
    {
        [$cart,$inventory] = $this->fixture();
        $real = app(ReserveOrderStockAction::class);
        $this->mock(ReserveOrderStockAction::class)->shouldReceive('handle')->once()->andReturnUsing(function (Order $order) use ($real): void {
            $real->handle($order);
            throw new \RuntimeException('simulate downstream failure');
        });
        Event::fake([OrderPlaced::class]);
        try {
            $this->place($cart);
            $this->fail('Checkout must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulate downstream failure', $exception->getMessage());
        }
        foreach (['orders', 'order_items', 'order_status_histories', 'payments', 'stock_movements', 'stock_reservations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
        $this->assertSame('0.000', $inventory->fresh()->reserved);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_preparing_exception_rolls_back_status_consumption_history_and_balances(): void
    {
        [$cart,$inventory] = $this->fixture();
        $actor = $this->actor();
        $order = $this->transition($this->place($cart), OrderStatus::Confirmed, $actor);
        $real = app(ConsumeOrderStockAction::class);
        $this->mock(ConsumeOrderStockAction::class)->shouldReceive('handle')->once()->andReturnUsing(function (Order $order, ?User $actor) use ($real): void {
            $real->handle($order, $actor);
            throw new \RuntimeException('consume rollback');
        });
        try {
            $this->transition($order, OrderStatus::Preparing, $actor);
            $this->fail('Transition must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('consume rollback', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('10.000', $inventory->fresh()->on_hand);
        $this->assertSame('2.000', $inventory->fresh()->reserved);
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame(0, $order->statusHistory()->where('to_status', 'preparing')->count());
    }

    public function test_two_stale_carts_for_last_unit_allow_only_one_winner(): void
    {
        [$first,$inventory] = $this->fixture('1.000', 1);
        $second = Cart::factory()->for($inventory->branch)->create();
        CartItem::factory()->for($second)->for($inventory->product)->create();
        $this->place($first);
        try {
            $this->place($second);
            $this->fail('Last unit cannot be sold twice.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
        }
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame('1.000', $inventory->fresh()->reserved);
        $this->assertSame('0.000', $inventory->fresh()->available());
        $this->assertSame(CartStatus::Active, $second->fresh()->status);
    }

    public function test_option_variants_are_reserved_aggregately_and_cannot_exceed_stock(): void
    {
        $inventory = BranchInventory::factory()->create(['on_hand' => '3.000']);
        $order = Order::factory()->for($inventory->branch)->create();
        OrderItem::factory()->count(2)->for($order)->create(['product_id' => $inventory->product_id, 'product_uuid' => $inventory->product->uuid, 'quantity' => 2]);
        try {
            app(ReserveOrderStockAction::class)->handle($order);
            $this->fail('Aggregate stock is insufficient.');
        } catch (HttpResponseException) {
            $this->assertSame('0.000', $inventory->fresh()->reserved);
        }
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[DataProvider('trackingPolicies')]
    public function test_untracked_and_explicitly_disabled_tracking_require_no_reservation(bool $productTracked, bool $inventoryTracked): void
    {
        [$cart,$inventory] = $this->fixture('0.000', 2);
        $inventory->product->update(['tracks_inventory' => $productTracked]);
        $inventory->update(['is_tracking' => $inventoryTracked]);
        $this->place($cart);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function trackingPolicies(): array
    {
        return [[false, true], [false, false], [true, false]];
    }

    public function test_tracking_disabled_after_checkout_still_settles_existing_reservation(): void
    {
        [$cart,$inventory] = $this->fixture();
        $order = $this->place($cart);
        $inventory->update(['is_tracking' => false]);
        $inventory->product->update(['tracks_inventory' => false]);
        app(ConsumeOrderStockAction::class)->handle($order);
        $this->assertSame('8.000', $inventory->fresh()->on_hand);
        $this->assertSame('0.000', $inventory->fresh()->reserved);
    }

    public function test_reservation_ledger_references_and_unique_constraint_are_enforced(): void
    {
        [$cart,$inventory] = $this->fixture();
        $order = $this->place($cart);
        $reservation = StockReservation::firstOrFail();
        $this->assertSame($order->id, $reservation->order_id);
        $this->assertSame($inventory->id, $reservation->inventory->id);
        $this->assertSame($order->uuid, StockMovement::firstOrFail()->reference_uuid);
        $this->expectException(UniqueConstraintViolationException::class);
        StockReservation::factory()->create(['order_id' => $order->id, 'order_item_id' => $reservation->order_item_id, 'branch_inventory_id' => $inventory->id]);
    }

    public function test_reserving_crosses_threshold_and_releasing_recovers_without_duplicate_alerts(): void
    {
        [$cart,$inventory] = $this->fixture('6.000', 2);
        Event::fake([LowStockDetected::class, StockRecovered::class]);
        $order = $this->place($cart);
        Event::assertDispatchedTimes(LowStockDetected::class, 1);
        app(ReserveOrderStockAction::class)->handle($order);
        Event::assertDispatchedTimes(LowStockDetected::class, 1);
        app(ReleaseOrderStockAction::class)->handle($order);
        app(ReleaseOrderStockAction::class)->handle($order);
        Event::assertDispatchedTimes(StockRecovered::class, 1);
        app(ConsumeOrderStockAction::class)->handle($order);
        $this->assertSame('6.000', $inventory->fresh()->on_hand);
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
    }

    public function test_multiple_variants_consume_and_release_their_own_reservations_exactly_once(): void
    {
        [$cart,$inventory] = $this->fixture('10.000', 2);
        $order = $this->place($cart);
        $item = $order->items()->firstOrFail();
        OrderItem::factory()->for($order)->create(['product_id' => $inventory->product_id, 'product_uuid' => $item->product_uuid, 'quantity' => 3]);
        app(ReserveOrderStockAction::class)->handle($order);
        $this->assertSame('5.000', $inventory->fresh()->reserved);
        app(ConsumeOrderStockAction::class)->handle($order);
        app(ConsumeOrderStockAction::class)->handle($order);
        app(ReleaseOrderStockAction::class)->handle($order);
        $this->assertSame('5.000', $inventory->fresh()->on_hand);
        $this->assertSame('0.000', $inventory->fresh()->reserved);
        $this->assertSame(2, StockMovement::where('type', 'sale')->count());
    }

    public function test_kitchen_endpoint_consumes_inventory_in_the_existing_workflow(): void
    {
        [$cart,$inventory] = $this->fixture();
        $actor = $this->actor();
        $order = $this->transition($this->place($cart), OrderStatus::Confirmed, $actor);
        $kitchen = User::factory()->create()->assignRole('kitchen');
        $this->actingAs($kitchen, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/start-preparing')
            ->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/start-preparing')->assertStatus(409);
        $this->assertSame('8.000', $inventory->fresh()->on_hand);
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
    }
}
