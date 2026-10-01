<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;

class OrderPaymentPolicy
{
    public function canConfirm(Order $order): bool
    {
        return $order->payment_method === PaymentMethod::Cash || $order->payment_status === PaymentStatus::Paid;
    }
}
