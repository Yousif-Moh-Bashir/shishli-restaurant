<?php

namespace Tests\Feature;

use App\Actions\Payments\CollectCashPaymentAction;
use App\Actions\Payments\SyncOrderPaymentStatusAction;
use App\Enums\PaymentStatus;
use App\Events\PaymentSucceeded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('confirmationPolicies')]
    public function test_confirmation_obeys_cash_and_online_payment_policy(string $method, string $status, bool $allowed): void
    {
        $manager = $this->manager();
        $order = Order::factory()->create(['payment_method' => $method, 'payment_status' => $status, 'total' => '85.00']);
        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/confirm');
        if ($allowed) {
            $response->assertOk()->assertJsonPath('data.status', 'confirmed');
            $this->assertDatabaseCount('order_status_histories', 1);
        } else {
            $response->assertUnprocessable()->assertJsonPath('message', 'ORDER_PAYMENT_REQUIRED');
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertDatabaseCount('order_status_histories', 0);
        }
    }

    public static function confirmationPolicies(): array
    {
        return ['unpaid cash' => ['cash', 'pending', true], 'unpaid online' => ['card', 'pending', false],
            'failed online' => ['card', 'failed', false], 'paid online' => ['card', 'paid', true]];
    }

    #[DataProvider('cancellationPolicies')]
    public function test_order_cancellation_cancels_only_pending_cash_and_never_automatically_refunds(string $method, string $status, string $expected): void
    {
        $this->freezeTime();
        $manager = $this->manager();
        $order = Order::factory()->create(['payment_method' => $method, 'payment_status' => $status, 'total' => '85.00']);
        $payment = Payment::factory()->for($order)->create(['method' => $method, 'provider' => $method === 'card' ? 'test' : null, 'status' => $status]);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/admin/orders/'.$order->uuid.'/cancel', ['reason' => 'Customer requested'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.payment.status', $expected);
        $this->assertSame($expected, $payment->fresh()->status->value);
        $this->assertSame('0.00', $payment->fresh()->refunded_amount);
        $this->assertDatabaseCount('payment_transactions', 0);
        if ($expected === 'cancelled') {
            $this->assertSame(now()->toIso8601String(), $payment->fresh()->cancelled_at->toIso8601String());
        }
    }

    public static function cancellationPolicies(): array
    {
        return ['pending cash' => ['cash', 'pending', 'cancelled'], 'paid cash' => ['cash', 'paid', 'paid'],
            'paid online' => ['card', 'paid', 'paid'], 'pending online' => ['card', 'pending', 'pending']];
    }

    public function test_success_event_waits_for_outer_commit(): void
    {
        $actor = $this->manager();
        $payment = Payment::factory()->cash()->create();
        $events = [];
        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) use (&$events): void {
            $events[] = [$event->payment->uuid, $event->order->payment_status->value, DB::transactionLevel()];
        });
        DB::beginTransaction();
        app(CollectCashPaymentAction::class)->handle($payment->order, $actor);
        $this->assertSame([], $events);
        DB::commit();
        $this->assertSame([[$payment->uuid, 'paid', 0]], $events);
    }

    public function test_outer_rollback_discards_collection_event_transaction_and_status_updates(): void
    {
        $actor = $this->manager();
        $payment = Payment::factory()->cash()->create();
        $events = [];
        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) use (&$events): void {
            $events[] = $event;
        });
        DB::beginTransaction();
        app(CollectCashPaymentAction::class)->handle($payment->order, $actor);
        DB::rollBack();
        $this->assertSame([], $events);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->order->fresh()->payment_status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_financial_amounts_remain_fixed_when_order_total_changes_later(): void
    {
        $actor = $this->manager();
        $payment = Payment::factory()->cash()->create();
        app(CollectCashPaymentAction::class)->handle($payment->order, $actor);
        $payment->order->update(['total' => '100.00']);
        $this->assertSame('85.00', $payment->fresh()->amount);
        $this->assertSame('85.00', $payment->transactions()->sole()->amount);
    }

    public function test_failed_attempt_does_not_overwrite_successful_payment_summary(): void
    {
        $order = Order::factory()->create(['payment_method' => 'card', 'total' => '85.00']);
        Payment::factory()->for($order)->online()->failed()->create();
        Payment::factory()->for($order)->online()->paid()->create();
        app(SyncOrderPaymentStatusAction::class)->handle($order);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Payment::factory()->for($order)->online()->failed()->create();
        app(SyncOrderPaymentStatusAction::class)->handle($order);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_financial_snapshots_reject_amount_changes_after_payment_creation(): void
    {
        $payment = Payment::factory()->cash()->create();
        try {
            $payment->update(['amount' => '1.00']);
            $this->fail('Payment snapshots cannot be altered.');
        } catch (\LogicException) {
            $this->assertSame('85.00', $payment->fresh()->amount);
        }
    }

    private function manager(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('manager');
    }
}
