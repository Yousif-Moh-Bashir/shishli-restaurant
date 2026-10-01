<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Validation\ValidationException;

class OrderStateMachine
{
    /** @return list<OrderStatus> */
    public function allowedTransitions(OrderStatus $from, OrderType $type): array
    {
        return match ($from) {
            OrderStatus::Pending => [OrderStatus::Confirmed, OrderStatus::Cancelled],
            OrderStatus::Confirmed => [OrderStatus::Preparing, OrderStatus::Cancelled],
            OrderStatus::Preparing => [OrderStatus::Ready, OrderStatus::Cancelled],
            OrderStatus::Ready => [$type === OrderType::Pickup ? OrderStatus::Completed : OrderStatus::OutForDelivery, OrderStatus::Cancelled],
            OrderStatus::OutForDelivery => $type === OrderType::Delivery ? [OrderStatus::Completed] : [],
            OrderStatus::Completed, OrderStatus::Cancelled => [],
        };
    }

    public function canTransition(OrderStatus $from, OrderStatus $to, OrderType $type): bool
    {
        return in_array($to, $this->allowedTransitions($from, $type), true);
    }

    public function transition(OrderStatus $from, OrderStatus $to, OrderType $type): OrderStatus
    {
        if (! $this->canTransition($from, $to, $type)) {
            throw ValidationException::withMessages(['status' => 'لا يمكن نقل الطلب من '.$from->value.' إلى '.$to->value.'.']);
        }

        return $to;
    }
}
