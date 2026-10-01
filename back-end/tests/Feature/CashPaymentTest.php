<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentSucceeded;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_checkout_creates_one_pending_payment_from_trusted_order_total(): void
    {
        $assignment = BranchProduct::factory()->create(['price_override' => '19.99']);
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 3])->assertCreated();
        $payload = ['type' => 'pickup', 'payment_method' => 'cash', 'customer' => ['name' => 'Cash customer', 'phone' => '0501234567'],
            'amount' => '1.00', 'payment_status' => 'paid'];
        $result = $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.payment.status', 'pending');

        $order = Order::where('uuid', $result->json('data.id'))->sole();
        $payment = $order->payments()->sole();
        $this->assertSame('59.97', $payment->amount);
        $this->assertSame('SAR', $payment->currency);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->provider);
        $this->assertNull($payment->paid_at);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->postJson('/api/v1/checkout', $payload)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    #[DataProvider('cashRoles')]
    public function test_cash_collection_obeys_permissions_and_records_single_sale(string $role, bool $allowed): void
    {
        $this->freezeTime();
        $actor = $this->staff($role);
        $payment = Payment::factory()->cash()->create();
        $order = $payment->order;
        Event::fake([PaymentSucceeded::class]);

        $response = $this->actingAs($actor, 'sanctum')->postJson($this->path($order), [
            'amount' => '1.00', 'status' => 'paid', 'paid_at' => '2000-01-01', 'metadata' => ['cvv' => '999'],
        ]);
        if (! $allowed) {
            $response->assertForbidden();
            $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
            $this->assertDatabaseCount('payment_transactions', 0);
            Event::assertNotDispatched(PaymentSucceeded::class);

            return;
        }
        $response->assertOk()->assertJsonPath('data.amount', '85.00')->assertJsonPath('data.status', 'paid');
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(now()->toIso8601String(), $payment->fresh()->paid_at->toIso8601String());
        $transaction = $payment->transactions()->sole();
        $this->assertSame('sale', $transaction->type->value);
        $this->assertSame('succeeded', $transaction->status->value);
        $this->assertSame('85.00', $transaction->amount);
        $this->assertSame(['collected_by' => $actor->id], $transaction->metadata);
        $this->postJson($this->path($order))->assertOk()->assertJsonPath('data.id', $payment->uuid);
        $this->assertDatabaseCount('payment_transactions', 1);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public static function cashRoles(): array
    {
        return ['super admin' => ['super_admin', true], 'manager' => ['manager', true], 'cashier' => ['cashier', true],
            'kitchen' => ['kitchen', false], 'customer' => ['customer', false]];
    }

    public function test_guest_and_unprivileged_actor_cannot_collect_cash(): void
    {
        $payment = Payment::factory()->cash()->create();
        $this->postJson($this->path($payment->order))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($this->path($payment->order))->assertForbidden();
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    #[DataProvider('invalidOrders')]
    public function test_ineligible_cash_collection_returns_422_without_mutation(string $case, string $error): void
    {
        $actor = $this->staff('manager');
        $payment = Payment::factory()->cash()->create();
        $order = $payment->order;
        if ($case === 'cancelled') {
            $order->update(['status' => 'cancelled']);
        }
        if ($case === 'online') {
            $order->update(['payment_method' => 'card']);
        }
        if ($case === 'amount') {
            $order->update(['total' => '90.00']);
        }
        if ($case === 'missing') {
            $payment->delete();
        }

        $this->actingAs($actor, 'sanctum')->postJson($this->path($order))->assertUnprocessable()->assertJsonPath('message', $error);

        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public static function invalidOrders(): array
    {
        return ['cancelled' => ['cancelled', 'ORDER_CANCELLED'], 'online' => ['online', 'NOT_A_CASH_ORDER'],
            'changed amount' => ['amount', 'PAYMENT_AMOUNT_MISMATCH'], 'no pending payment' => ['missing', 'CASH_PAYMENT_NOT_FOUND']];
    }

    public function test_cash_collection_transaction_failure_rolls_back_all_financial_changes(): void
    {
        $actor = $this->staff('manager');
        $payment = Payment::factory()->cash()->create();
        Event::fake([PaymentSucceeded::class]);
        $event = 'eloquent.creating: '.PaymentTransaction::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated transaction write failure');
        });

        try {
            $this->actingAs($actor, 'sanctum')->postJson($this->path($payment->order))->assertServerError();
        } finally {
            Event::forget($event);
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame(PaymentStatus::Pending, $payment->order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 0);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_payment_record_failure_rolls_back_cash_checkout(): void
    {
        $assignment = BranchProduct::factory()->create();
        $cart = $this->postJson('/api/v1/cart', ['branch_uuid' => $assignment->branch->uuid])->assertCreated();
        $this->withHeader('X-Cart-Token', $cart->json('data.token'));
        $this->postJson('/api/v1/cart/items', ['product_uuid' => $assignment->product->uuid, 'quantity' => 1])->assertCreated();
        $event = 'eloquent.creating: '.Payment::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Simulated payment write failure');
        });
        try {
            $this->postJson('/api/v1/checkout', ['type' => 'pickup', 'payment_method' => 'cash',
                'customer' => ['name' => 'Cash customer', 'phone' => '0501234567']])->assertServerError();
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseHas('carts', ['uuid' => $cart->json('data.id'), 'status' => 'active']);
    }

    public function test_completed_cash_order_stays_unpaid_until_explicit_collection(): void
    {
        $actor = $this->staff('manager');
        $order = Order::factory()->create(['status' => 'ready', 'total' => '85.00']);
        $payment = Payment::factory()->for($order)->cash()->create();
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/complete')->assertOk();
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->postJson($this->path($order))->assertOk();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    private function staff(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole($role);
    }

    private function path(Order $order): string
    {
        return '/api/v1/admin/orders/'.$order->uuid.'/payments/cash/collect';
    }
}
