<?php

namespace App\Data\Payments;

use App\Enums\PaymentStatus;

/** Values must come from a verified provider API, never a browser redirect. */
final readonly class PaymentProviderResult
{
    public function __construct(public string $providerPaymentId, public PaymentStatus $status,
        public string $amount, public string $currency, public ?string $providerReference = null,
        public ?string $checkoutUrl = null, public ?string $providerTransactionId = null) {}
}
