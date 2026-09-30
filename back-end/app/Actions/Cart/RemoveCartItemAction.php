<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Services\CartMutation;

class RemoveCartItemAction
{
    public function __construct(private CartMutation $mutation) {}

    public function handle(Cart $cart, string $itemUuid): Cart
    {
        return $this->mutation->run($cart, function (Cart $cart) use ($itemUuid): void {
            $cart->items()->where('uuid', $itemUuid)->firstOrFail()->delete();
        }, requiresOrders: false);
    }
}
