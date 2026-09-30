<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Services\CartItemWriter;
use App\Services\CartMutation;

class UpdateCartItemOptionsAction
{
    public function __construct(private CartMutation $mutation, private CartItemWriter $writer) {}

    public function handle(Cart $cart, string $itemUuid, array $selections): Cart
    {
        return $this->mutation->run($cart, function (Cart $cart) use ($itemUuid, $selections): void {
            $item = $cart->items()->where('uuid', $itemUuid)->with('product')->firstOrFail();
            [$product, $assignment] = $this->writer->product($cart, $item->product->uuid);
            $this->writer->write($cart, $product, $assignment, $selections, $item->quantity, $item);
        });
    }
}
