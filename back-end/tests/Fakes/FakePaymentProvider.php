<?php

namespace Tests\Fakes;

use App\Contracts\Payments\PaymentProviderInterface;
use App\Data\Payments\CreatePaymentData;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\WebhookPaymentData;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

class FakePaymentProvider implements PaymentProviderInterface
{
    public const SECRET = 'test-only-webhook-secret';

    /** @var list<CreatePaymentData> */
    public array $calls = [];

    public PaymentStatus $nextStatus = PaymentStatus::Processing;

    public bool $throwOnCreate = false;

    public ?\Closure $duringCreate = null;

    public ?string $nextProviderId = null;

    public string $checkoutUrl = 'https://checkout.example.test/session';

    public function supports(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::Card;
    }

    public function createPayment(CreatePaymentData $data): PaymentProviderResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Network call inside a database transaction.');
        }
        $this->calls[] = $data;
        if ($this->throwOnCreate) {
            throw new \RuntimeException('secret provider error that must never be exposed');
        }
        if ($this->duringCreate !== null) {
            ($this->duringCreate)($data);
        }

        return new PaymentProviderResult($this->nextProviderId ?? 'provider-'.$data->paymentUuid, $this->nextStatus,
            $data->amount, $data->currency, 'ref-'.$data->paymentUuid, $this->checkoutUrl);
    }

    public function retrievePayment(string $providerPaymentId): PaymentProviderResult
    {
        foreach ($this->calls as $data) {
            if ('provider-'.$data->paymentUuid === $providerPaymentId) {
                return new PaymentProviderResult($providerPaymentId, $this->nextStatus, $data->amount, $data->currency);
            }
        }
        throw new \RuntimeException('Payment not found.');
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $timestamp = $headers['x-payment-timestamp'][0] ?? '';
        $signature = $headers['x-payment-signature'][0] ?? '';

        return ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= config('payments.webhooks.timestamp_tolerance_seconds')
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, self::SECRET), $signature);
    }

    public function parseWebhook(string $rawBody): WebhookPaymentData
    {
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (! is_string($data['amount'] ?? null) || ! is_string($data['currency'] ?? null)) {
            throw new \UnexpectedValueException('Invalid monetary data.');
        }

        return new WebhookPaymentData($data['event_id'] ?? null, $data['event_type'] ?? 'payment.updated',
            $data['payment_id'] ?? null, isset($data['status']) ? PaymentStatus::from($data['status']) : null,
            $data['amount'], $data['currency'], $data['reference'] ?? null, $data['transaction_id'] ?? null, $data['payment_uuid'] ?? null);
    }
}
