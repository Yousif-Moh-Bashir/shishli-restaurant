<?php

namespace Database\Factories;

use App\Actions\Cart\RecalculateCartTotalsAction;
use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CartItem> */
class CartItemFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (CartItem $item): void {
            BranchProduct::firstOrCreate(['branch_id' => $item->cart->branch_id, 'product_id' => $item->product_id]);
            app(RecalculateCartTotalsAction::class)->handle($item->cart);
        });
    }

    public function definition(): array
    {
        return ['cart_id' => Cart::factory(), 'product_id' => Product::factory()->state(['base_price' => '20.00']),
            'quantity' => 1, 'base_price' => '20.00', 'options_total' => '0.00', 'unit_price' => '20.00', 'line_total' => '20.00',
            'configuration_hash' => fn (array $attributes): string => hash('sha256', json_encode([(int) $attributes['product_id'], []], JSON_THROW_ON_ERROR))];
    }
}
