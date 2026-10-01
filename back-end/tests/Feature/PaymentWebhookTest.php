<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\WebhookEventStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfiguresPaymentProvider;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use ConfiguresPaymentProvider, DatabaseMigrations;

    public function test_valid_paid_webhook_synchronizes_order_and_stores_only_safe_payload(): void
    {
        $this->freezeTime();
        $this->configureProvider();
        $payment = $this->payment();
        Event::fake([PaymentSucceeded::class]);
        $payload = $this->payload($payment) + ['card_number' => '4111111111111111', 'cvv' => '123', 'token' => 'private'];

        $this->sendWebhook($payload)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
        $this->assertSame(now()->toIso8601String(), $payment->fresh()->paid_at->toIso8601String());
        $this->assertSame('85.00', $payment->transactions()->sole()->amount);
        $this->assertSame('succeeded', $payment->transactions()->sole()->status->value);
        $event = PaymentWebhookEvent::sole();
        $this->assertTrue($event->signature_valid);
        $this->assertSame(WebhookEventStatus::Processed, $event->status);
        $this->assertSame(['status' => 'paid', 'amount' => '85.00', 'currency' => 'SAR'], $event->payload);
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $event): bool => $event->payment->uuid === $payment->uuid && $event->order->payment_status === PaymentStatus::Paid);
    }

    public function test_duplicate_event_and_distinct_paid_event_create_only_one_sale_and_success_event(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        Event::fake([PaymentSucceeded::class]);
        $payload = $this->payload($payment);
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook(array_replace($payload, ['event_id' => 'another-paid-event']))->assertOk();
        $this->assertDatabaseCount('payment_webhook_events', 2);
        $this->assertDatabaseCount('payment_transactions', 1);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_missing_event_id_uses_deterministic_fingerprint(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $payload = $this->payload($payment);
        unset($payload['event_id']);
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertNull(PaymentWebhookEvent::sole()->provider_event_id);
    }

    #[DataProvider('invalidSignatures')]
    public function test_invalid_or_expired_signature_returns_401_before_any_mutation(bool $valid, int $age): void
    {
        $this->freezeTime();
        $this->configureProvider();
        $payment = $this->payment();
        Event::fake([PaymentSucceeded::class]);
        $this->sendWebhook($this->payload($payment), $valid, now()->timestamp - $age)->assertUnauthorized();
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public static function invalidSignatures(): array
    {
        return ['forged' => [false, 0], 'expired' => [true, 301]];
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->postJson('/api/v1/webhooks/payments/unknown', ['status' => 'paid'])->assertNotFound();
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    #[DataProvider('invalidFinancialData')]
    public function test_wrong_amount_currency_or_reference_cannot_mark_payment_paid(string $field, string $value, string $error): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        Event::fake([PaymentSucceeded::class]);
        $this->sendWebhook(array_replace($this->payload($payment), [$field => $value]))->assertOk();
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame(WebhookEventStatus::Failed, PaymentWebhookEvent::sole()->status);
        $this->assertSame($error, PaymentWebhookEvent::sole()->failure_message);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public static function invalidFinancialData(): array
    {
        return ['amount' => ['amount', '1.00', 'PAYMENT_AMOUNT_MISMATCH'], 'currency' => ['currency', 'USD', 'PAYMENT_CURRENCY_MISMATCH'],
            'invalid decimal' => ['amount', '85.001', 'PAYMENT_AMOUNT_INVALID'],
            'reference' => ['reference', 'wrong-reference', 'PROVIDER_REFERENCE_MISMATCH']];
    }

    public function test_unknown_provider_payment_id_mutates_nothing_and_can_retry_after_registration(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $payload = array_replace($this->payload($payment), ['payment_id' => 'not-yet-registered']);
        $this->sendWebhook($payload)->assertServiceUnavailable()->assertJsonPath('message', 'PROVIDER_PAYMENT_NOT_READY');
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
        $payment->provider_payment_id = 'not-yet-registered';
        $payment->save();
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_failed_webhook_keeps_order_pending_and_dispatches_safe_failure_event_once(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        Event::fake([PaymentFailed::class, PaymentSucceeded::class]);
        $payload = array_replace($this->payload($payment), ['status' => 'failed']);
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->failed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->order->fresh()->payment_status);
        $this->assertSame('pending', $payment->order->fresh()->status->value);
        $this->assertSame('failed', $payment->transactions()->sole()->status->value);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_paid_payment_cannot_be_downgraded_by_late_failed_event(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $payload = $this->payload($payment);
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook(array_replace($payload, ['event_id' => 'late-failure', 'status' => 'failed']))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(WebhookEventStatus::Failed, PaymentWebhookEvent::where('provider_event_id', 'late-failure')->sole()->status);
    }

    public function test_failed_attempt_cannot_be_reconciled_to_paid_without_explicit_provider_policy(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $payload = $this->payload($payment);
        $this->sendWebhook(array_replace($payload, ['status' => 'failed']))->assertOk();
        $this->sendWebhook(array_replace($payload, ['event_id' => 'late-paid']))->assertOk();
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->order->fresh()->payment_status);
        $this->assertSame('failed', $payment->transactions()->sole()->status->value);
    }

    public function test_two_attempts_cannot_both_be_marked_paid_for_one_order(): void
    {
        $this->configureProvider();
        $first = $this->payment();
        $second = Payment::factory()->for($first->order)->online()->processing()->create([
            'provider_payment_id' => 'second-provider-id', 'provider_reference' => 'second-reference',
        ]);
        $this->sendWebhook($this->payload($first))->assertOk();
        $this->sendWebhook(array_replace($this->payload($second), ['event_id' => 'second-sale']))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Processing, $second->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('ORDER_ALREADY_PAID', PaymentWebhookEvent::where('provider_event_id', 'second-sale')->sole()->failure_message);
    }

    public function test_provider_names_scope_payment_lookup(): void
    {
        $this->configureProvider();
        $payment = Payment::factory()->online()->processing()->create([
            'provider' => 'other', 'provider_payment_id' => 'shared-id', 'provider_reference' => 'shared-ref',
        ]);
        $this->sendWebhook($this->payload($payment))->assertServiceUnavailable();
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_provider_reference_can_identify_payment_when_payment_id_is_absent(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $payload = $this->payload($payment);
        unset($payload['payment_id']);
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_verified_unsupported_event_is_ignored_without_payment_mutation(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $payload = $this->payload($payment);
        unset($payload['status']);
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame(WebhookEventStatus::Ignored, PaymentWebhookEvent::sole()->status);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_webhook_body_limit_is_enforced_before_storage(): void
    {
        $this->configureProvider();
        config(['payments.webhooks.max_body_bytes' => 10]);
        $payment = $this->payment();
        $this->sendWebhook($this->payload($payment))->assertStatus(413);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    private function payment(): Payment
    {
        return Payment::factory()->online()->processing()->create(['provider_payment_id' => 'provider-id', 'provider_reference' => 'provider-ref']);
    }

    public function test_provider_transaction_collision_rolls_back_second_payment_before_recording_failure(): void
    {
        $this->configureProvider();
        $first = $this->payment();
        $second = Payment::factory()->online()->processing()->create(['provider_payment_id' => 'second-id', 'provider_reference' => 'second-ref']);
        Event::fake([PaymentSucceeded::class]);
        $this->sendWebhook($this->payload($first))->assertOk();

        $this->sendWebhook(array_replace($this->payload($second), ['event_id' => 'conflicting-transaction']))->assertOk();

        $this->assertSame(PaymentStatus::Processing, $second->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $second->order->fresh()->payment_status);
        $this->assertNull($second->fresh()->paid_at);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('PROVIDER_REFERENCE_COLLISION', PaymentWebhookEvent::where('provider_event_id', 'conflicting-transaction')->sole()->failure_message);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_signed_payload_with_numeric_amount_is_rejected_before_registration(): void
    {
        $this->configureProvider();
        $payment = $this->payment();
        $this->sendWebhook(array_replace($this->payload($payment), ['amount' => 85]))->assertBadRequest();
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    private function payload(Payment $payment): array
    {
        return ['event_id' => 'event-1', 'payment_id' => $payment->provider_payment_id, 'reference' => $payment->provider_reference,
            'status' => 'paid', 'amount' => '85.00', 'currency' => 'SAR', 'transaction_id' => 'sale-id'];
    }
}
