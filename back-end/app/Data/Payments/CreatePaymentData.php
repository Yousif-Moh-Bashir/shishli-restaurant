<?php

namespace App\Data\Payments;

use App\Enums\PaymentMethod;

final readonly class CreatePaymentData
{
    public function __construct(public string $paymentUuid, public string $orderUuid, public PaymentMethod $method,
        public string $amount, public string $currency, public string $idempotencyKey) {}
}
