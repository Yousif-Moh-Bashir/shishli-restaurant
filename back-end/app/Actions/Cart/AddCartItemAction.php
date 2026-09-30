<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Services\CartItemWriter;
use App\Services\CartMutation;

class AddCartItemAction
{
    public function __construct(private CartMutation $mutation, private CartItemWriter $writer) {}

    public function handle(Cart $cart, array $data): Cart
    {
        return $this->mutation->run($cart, function (Cart $cart) use ($data): void {
            [$product, $assignment] = $this->writer->product($cart, $data['product_uuid']);
            $this->writer->write($cart, $product, $assignment, $data['options'] ?? [], $data['quantity']);
        });
    }
}
