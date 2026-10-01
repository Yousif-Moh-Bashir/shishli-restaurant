<?php

namespace App\Services;

use App\Models\Order;

class GuestOrderAccess
{
    public function authorize(Order $order, ?string $token): void
    {
        abort_unless($order->user_id === null && $token !== null && strlen($token) === 64
            && $order->access_token !== null && hash_equals($order->access_token, $token), 404);
    }
}
