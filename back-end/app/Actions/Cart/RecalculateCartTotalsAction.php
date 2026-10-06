<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Services\CartStateService;
use App\Services\Money;

class RecalculateCartTotalsAction
{
    public function __construct(private CartStateService $state) {}

    public function handle(Cart $cart): void
    {
        $subtotal = 0;
        foreach ($cart->items()->pluck('line_total') as $line) {
            $subtotal += Money::minor((string) $line);
        }
        $amount = Money::decimal($subtotal);
        $cart->update(['subtotal' => $amount, 'total' => $amount]);
        $this->state->load($cart);
        $cart->save();
    }
}
