<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;

class OrderPaymentPolicy
{
    public function canConfirm(Order $order): bool
    {
        return $order->payment_method->isOffline() || $order->payment_status === PaymentStatus::Paid;
    }
}
