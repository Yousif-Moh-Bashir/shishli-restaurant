<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Services\CartMutation;

class ClearCartAction
{
    public function __construct(private CartMutation $mutation) {}

    public function handle(Cart $cart): Cart
    {
        return $this->mutation->run($cart, function (Cart $cart): void {
            $cart->items()->delete();
        }, requiresOrders: false);
    }
}
