<?php
namespace App\Actions\Payments;
use App\Enums\WebhookEventStatus;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Money;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PaymentStatusWriter;
use Illuminate\Support\Facades\DB;
use Throwable;
class HandlePaymentWebhookAction
{
    public function __construct(private PaymentProviderManager $providers, private PaymentStatusWriter $writer) {}
    /** @param array<string, list<string>> $headers */
    public function handle(string $providerName, string $rawBody, array $headers): PaymentWebhookEvent
    {
        $provider = $this->providers->webhookProvider($providerName);
        if (strlen($rawBody) > config('payments.webhooks.max_body_bytes', 65536)) {
            throw new PaymentException('WEBHOOK_BODY_TOO_LARGE', 413);
        }
        try { $valid = $provider->verifyWebhook($rawBody, $headers); }
        catch (Throwable) { $valid = false; }
        if (! $valid) { throw new PaymentException('INVALID_WEBHOOK_SIGNATURE', 401); }
        try { $data = $provider->parseWebhook($rawBody); }
        catch (Throwable) { throw new PaymentException('INVALID_WEBHOOK_PAYLOAD', 400); }
        if (strlen($data->providerEventId ?? '') > 255 || strlen($data->eventType) > 255
            || strlen($data->providerPaymentId ?? '') > 255 || strlen($data->providerReference ?? '') > 255
            || strlen($data->providerTransactionId ?? '') > 255) {
            throw new PaymentException('INVALID_WEBHOOK_PAYLOAD', 400);
        }
        $fingerprint = hash('sha256', $data->providerEventId !== null ? 'event:'.$data->providerEventId : $rawBody);
        $event = PaymentWebhookEvent::firstOrCreate(['provider' => $providerName, 'fingerprint' => $fingerprint], [
            'provider_event_id' => $data->providerEventId, 'event_type' => $data->eventType, 'signature_valid' => true,
            'status' => WebhookEventStatus::Received,
            'payload' => ['status' => $data->status?->value, 'amount' => $data->amount, 'currency' => $data->currency],
        ]);
        return DB::transaction(function () use ($event, $data, $providerName): PaymentWebhookEvent {
            $lockedEvent = PaymentWebhookEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($lockedEvent->status !== WebhookEventStatus::Received) { return $lockedEvent; }
            if ($data->status === null) {
                $lockedEvent->status = WebhookEventStatus::Ignored;
                $lockedEvent->processed_at = now();
                $lockedEvent->save();
                return $lockedEvent;
            }
            $payment = $data->providerPaymentId !== null
                ? Payment::where('provider', $providerName)->where('provider_payment_id', $data->providerPaymentId)->first()
                : ($data->providerReference !== null
                    ? Payment::where('provider', $providerName)->where('provider_reference', $data->providerReference)->first() : null);
            if ($payment === null) {
                $lockedEvent->status = WebhookEventStatus::Failed;
                $lockedEvent->failure_message = 'PAYMENT_NOT_FOUND';
                $lockedEvent->save();
                return $lockedEvent;
            }
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $lockedEvent->payment_id = $payment->id;
            try {
                if ($data->providerReference !== null && $payment->provider_reference !== null
                    && $data->providerReference !== $payment->provider_reference) {
                    throw new PaymentException('PROVIDER_REFERENCE_MISMATCH');
                }
                if (Money::minor($data->amount) !== Money::minor($payment->amount)) { throw new PaymentException('PAYMENT_AMOUNT_MISMATCH'); }
                if ($data->currency !== $payment->currency) { throw new PaymentException('PAYMENT_CURRENCY_MISMATCH'); }
                $this->writer->apply($payment, $order, $data->status, $data->providerTransactionId, $lockedEvent->uuid);
                $lockedEvent->status = WebhookEventStatus::Processed;
                $lockedEvent->processed_at = now();
            } catch (PaymentException $exception) {
                $lockedEvent->status = WebhookEventStatus::Failed;
                $lockedEvent->failure_message = $exception->errorCode;
            } catch (\Illuminate\Validation\ValidationException) {
                $lockedEvent->status = WebhookEventStatus::Failed;
                $lockedEvent->failure_message = 'PAYMENT_AMOUNT_INVALID';
            }
            $lockedEvent->save();
            return $lockedEvent;
        }, 3);
    }
}
