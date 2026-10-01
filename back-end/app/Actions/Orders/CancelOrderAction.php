<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class CancelOrderAction
{
    public function __construct(private TransitionOrderStatusAction $transition) {}

    public function handle(Order $order, string $reason, ?User $actor, OrderStatusSource $source, ?string $guestToken = null): Order
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']])->validate();

        return $this->transition->handle($order, OrderStatus::Cancelled, $actor, $source, $reason, $guestToken);
    }
}
