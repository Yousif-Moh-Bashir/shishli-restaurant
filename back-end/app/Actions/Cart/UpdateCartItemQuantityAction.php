<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Services\CartItemWriter;
use App\Services\CartMutation;
use App\Services\CartStateService;

class UpdateCartItemQuantityAction
{
    public function __construct(private CartMutation $mutation, private CartItemWriter $writer, private CartStateService $state) {}

    public function handle(Cart $cart, string $itemUuid, int $quantity): Cart
    {
        return $this->mutation->run($cart, function (Cart $cart) use ($itemUuid, $quantity): void {
            $item = $cart->items()->where('uuid', $itemUuid)->with(['product', 'options.optionGroup', 'options.optionValue'])->firstOrFail();
            [$product, $assignment] = $this->writer->product($cart, $item->product->uuid);
            $this->writer->write($cart, $product, $assignment, $this->state->selections($item), $quantity, $item);
        });
    }
}
