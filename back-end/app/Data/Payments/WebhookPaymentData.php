<?php
namespace App\Data\Payments;
use App\Enums\PaymentStatus;
final readonly class WebhookPaymentData
{
    public function __construct(public ?string $providerEventId, public string $eventType,
        public ?string $providerPaymentId, public ?PaymentStatus $status, public string $amount,
        public string $currency, public ?string $providerReference = null, public ?string $providerTransactionId = null) {}
}
