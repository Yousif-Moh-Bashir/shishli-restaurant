<?php

namespace App\Services\Notifications;

use App\Enums\OrderType;
use App\Models\Order;

readonly class NotificationContext
{
    public function __construct(public string $referenceType, public string $referenceUuid, public string $orderNumber, public OrderType $orderType, public string $customerName, public ?string $phone, public ?string $email) {}

    public static function fromOrder(Order $order, ?string $paymentUuid = null): self
    {
        return new self($paymentUuid === null ? 'order' : 'payment', $paymentUuid ?? $order->uuid, $order->order_number, $order->type, $order->customer_name, $order->customer_phone, $order->customer_email);
    }
}
