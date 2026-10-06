<?php

namespace App\Services;

use App\Models\Order;

class GuestOrderAccess
{
    public function matches(?Order $order, #[\SensitiveParameter] ?string $token): bool
    {
        $validHash = hash_equals($order?->guest_access_token_hash ?? str_repeat('0', 64), hash('sha256', $token ?? ''));

        return $validHash && $token !== null && strlen($token) === 64 && $order !== null && $order->user_id === null
            && $order->guest_access_token_expires_at !== null && $order->guest_access_token_expires_at->isFuture();
    }

    public function authorize(Order $order, #[\SensitiveParameter] ?string $token): void
    {
        abort_unless($this->matches($order, $token), 404);
    }
}
