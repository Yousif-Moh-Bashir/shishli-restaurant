<?php

namespace Tests\Feature;

use App\Data\Payments\CreatePaymentData;
use App\Enums\PaymentStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfiguresPaymentProvider;
use Tests\TestCase;

class InitiatePaymentTest extends TestCase
{
    use ConfiguresPaymentProvider, DatabaseMigrations;

    public function test_owner_initiates_online_payment_with_trusted_amount_and_normalized_result(): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $order = $this->order($user);

        $result = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'first-attempt')->postJson($this->path($order), [
            'amount' => '1.00', 'currency' => 'USD', 'status' => 'paid', 'provider' => 'attacker', 'method' => 'cash',
        ])->assertOk()->assertJsonPath('data.payment.amount', '85.00')->assertJsonPath('data.payment.currency', 'SAR')
            ->assertJsonPath('data.payment.status', 'processing')->assertJsonPath('data.checkout_url', 'https://checkout.example.test/session');

        $payment = Payment::where('uuid', $result->json('data.payment.id'))->sole();
        $this->assertSame('provider-'.$payment->uuid, $payment->provider_payment_id);
        $this->assertSame('ref-'.$payment->uuid, $payment->provider_reference);
        $this->assertSame('85.00', $provider->calls[0]->amount);
        $this->assertSame('SAR', $provider->calls[0]->currency);
        $this->assertSame('pending', $payment->transactions()->sole()->status->value);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $result->assertJsonMissingPath('data.payment.idempotency_key')->assertJsonMissingPath('data.payment.metadata')
            ->assertJsonMissingPath('data.payment.provider_payment_id');
    }

    public function test_no_real_provider_configuration_returns_503_and_creates_no_payment(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user);
        $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertServiceUnavailable()
            ->assertJsonPath('message', 'PAYMENT_PROVIDER_NOT_CONFIGURED');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_owner_and_guest_security_are_enforced_before_payment_creation(): void
    {
        $provider = $this->configureProvider();
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->postJson($this->path($order))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($this->path($order))->assertNotFound();
        $this->postJson('/api/v1/orders/'.$order->uuid.'/guest/payments')->assertNotFound();
        $guest = $this->order();
        $guest->forceFill(['access_token' => str_repeat('a', 64)])->save();
        $path = '/api/v1/orders/'.$guest->uuid.'/guest/payments';
        $this->withHeader('X-Order-Token', str_repeat('b', 64))->postJson($path)->assertNotFound();
        $this->withHeader('X-Order-Token', str_repeat('a', 64))->postJson($path)->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertCount(1, $provider->calls);
    }

    #[DataProvider('ineligibleOrders')]
    public function test_ineligible_order_returns_422_and_never_calls_provider(string $field, string $value, string $code): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $order = $this->order($user);
        $order->update([$field => $value]);
        $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertUnprocessable()->assertJsonPath('message', $code);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame([], $provider->calls);
    }

    public static function ineligibleOrders(): array
    {
        return ['cancelled' => ['status', 'cancelled', 'ORDER_CANCELLED'], 'already paid' => ['payment_status', 'paid', 'ORDER_ALREADY_PAID'],
            'cash' => ['payment_method', 'cash', 'PAYMENT_METHOD_NOT_SUPPORTED']];
    }

    public function test_same_key_replays_attempt_and_different_key_cannot_overlap_active_attempt(): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $order = $this->order($user);
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'same-key');
        $first = $this->postJson($this->path($order))->assertOk();
        $this->postJson($this->path($order))->assertOk()->assertJsonPath('data.payment.id', $first->json('data.payment.id'));
        $this->withHeader('Idempotency-Key', 'different-key')->postJson($this->path($order))->assertConflict()
            ->assertJsonPath('message', 'PAYMENT_ALREADY_IN_PROGRESS');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertCount(1, $provider->calls);
    }

    public function test_same_key_is_scoped_to_order_and_cannot_expose_another_owners_payment(): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $firstOrder = $this->order($user);
        $secondOrder = $this->order($user);
        $foreign = $this->order(User::factory()->create());
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'shared-key');
        $first = $this->postJson($this->path($firstOrder))->assertOk();
        $second = $this->postJson($this->path($secondOrder))->assertOk();
        $this->postJson($this->path($foreign))->assertNotFound();
        $this->assertNotSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        $this->assertDatabaseCount('payments', 2);
        $this->assertCount(2, $provider->calls);
    }

    public function test_failed_attempt_is_replayable_and_new_key_can_retry_without_cancelling_order(): void
    {
        $provider = $this->configureProvider();
        $provider->nextStatus = PaymentStatus::Failed;
        $user = User::factory()->create();
        $order = $this->order($user);
        Event::fake([PaymentFailed::class]);
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'declined');
        $this->postJson($this->path($order))->assertOk()->assertJsonPath('data.payment.status', 'failed');
        $this->postJson($this->path($order))->assertOk()->assertJsonPath('data.payment.status', 'failed');
        $provider->nextStatus = PaymentStatus::Processing;
        $this->withHeader('Idempotency-Key', 'retry')->postJson($this->path($order))->assertOk()->assertJsonPath('data.payment.status', 'processing');
        $this->assertDatabaseCount('payments', 2);
        $this->assertCount(2, $provider->calls);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame('pending', $order->fresh()->status->value);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
    }

    public function test_provider_timeout_does_not_allow_a_second_charge_or_expose_provider_exception(): void
    {
        $provider = $this->configureProvider();
        $provider->throwOnCreate = true;
        $user = User::factory()->create();
        $order = $this->order($user);
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'uncertain');
        $this->postJson($this->path($order))->assertServiceUnavailable()->assertJsonPath('message', 'PROVIDER_REQUEST_UNCERTAIN');
        $this->postJson($this->path($order))->assertOk()->assertJsonPath('data.payment.status', 'processing')
            ->assertJsonMissingPath('data.payment.failure_message');
        $this->withHeader('Idempotency-Key', 'retry')->postJson($this->path($order))->assertConflict();
        $this->assertDatabaseCount('payments', 1);
        $this->assertCount(1, $provider->calls);
    }

    public function test_verified_provider_paid_result_synchronizes_order_and_prevents_new_attempts(): void
    {
        $provider = $this->configureProvider();
        $provider->nextStatus = PaymentStatus::Paid;
        $user = User::factory()->create();
        $order = $this->order($user);
        Event::fake([PaymentSucceeded::class]);
        $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertOk()->assertJsonPath('data.payment.status', 'paid');
        $this->postJson($this->path($order))->assertUnprocessable()->assertJsonPath('message', 'ORDER_ALREADY_PAID');
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 1);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_database_rejects_duplicate_order_idempotency_key(): void
    {
        $payment = Payment::factory()->online()->create(['idempotency_key' => 'same']);
        try {
            Payment::factory()->for($payment->order)->online()->create(['idempotency_key' => 'same']);
            $this->fail('Duplicate scoped key must violate the database constraint.');
        } catch (QueryException) {
            $this->assertDatabaseCount('payments', 1);
        }
    }

    #[DataProvider('rawCardFields')]
    public function test_raw_card_credentials_are_rejected_with_422(string $field): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $order = $this->order($user);
        $this->actingAs($user, 'sanctum')->postJson($this->path($order), [$field => 'sensitive'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame([], $provider->calls);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function rawCardFields(): array
    {
        return [['card'], ['card_number'], ['cvv'], ['cvc'], ['pan'], ['payment_token']];
    }

    public function test_unsafe_checkout_url_is_not_returned_or_persisted(): void
    {
        $provider = $this->configureProvider();
        $provider->checkoutUrl = 'javascript:alert(1)';
        $user = User::factory()->create();
        $order = $this->order($user);
        $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertOk()->assertJsonPath('data.checkout_url', null);
        $this->assertNull(Payment::sole()->checkout_url);
    }

    public function test_provider_reference_collision_is_a_safe_conflict_and_does_not_mark_another_order_paid(): void
    {
        $provider = $this->configureProvider();
        $provider->nextProviderId = 'shared-provider-id';
        $user = User::factory()->create();
        $first = $this->order($user);
        $second = $this->order($user);
        $this->actingAs($user, 'sanctum')->postJson($this->path($first))->assertOk();
        $this->postJson($this->path($second))->assertConflict()->assertJsonPath('message', 'PROVIDER_REFERENCE_COLLISION');
        $this->assertSame(PaymentStatus::Pending, $first->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->payment_status);
    }

    public function test_initiation_inside_outer_transaction_is_rejected_before_provider_call(): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $order = $this->order($user);
        DB::beginTransaction();
        try {
            $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertConflict()
                ->assertJsonPath('message', 'PAYMENT_INITIATION_REQUIRES_COMMITTED_ORDER');
            $this->assertSame([], $provider->calls);
        } finally {
            DB::rollBack();
        }
    }

    private function order(?User $owner = null): Order
    {
        return Order::factory()->create(['user_id' => $owner?->id, 'payment_method' => 'card', 'total' => '85.00']);
    }

    public function test_early_paid_webhook_is_not_downgraded_by_initiation_result(): void
    {
        $provider = $this->configureProvider();
        $user = User::factory()->create();
        $order = $this->order($user);
        $provider->duringCreate = function (CreatePaymentData $data): void {
            $this->sendWebhook(['event_id' => 'early-paid', 'payment_uuid' => $data->paymentUuid,
                'payment_id' => 'provider-'.$data->paymentUuid, 'reference' => 'ref-'.$data->paymentUuid,
                'status' => 'paid', 'amount' => $data->amount, 'currency' => $data->currency])->assertOk();
        };

        $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertOk()
            ->assertJsonPath('data.payment.status', 'paid')->assertJsonPath('data.checkout_url', null);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('succeeded', Payment::sole()->transactions()->sole()->status->value);
    }

    public function test_uncertain_attempt_can_be_reconciled_by_verified_merchant_reference_webhook(): void
    {
        $provider = $this->configureProvider();
        $provider->throwOnCreate = true;
        $user = User::factory()->create();
        $order = $this->order($user);
        $this->actingAs($user, 'sanctum')->postJson($this->path($order))->assertServiceUnavailable();
        $payment = Payment::sole();

        $this->sendWebhook(['event_id' => 'timeout-paid', 'payment_uuid' => $payment->uuid,
            'payment_id' => 'provider-'.$payment->uuid, 'status' => 'paid', 'amount' => '85.00', 'currency' => 'SAR'])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertCount(1, $provider->calls);
    }

    private function path(Order $order): string
    {
        return '/api/v1/orders/'.$order->uuid.'/payments';
    }
}
