<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'product_uuid' => fake()->uuid(), 'product_name' => fake()->words(3, true),
            'product_sku' => fake()->bothify('SKU-####'), 'quantity' => 2, 'base_price' => '19.99',
            'options_total' => '0.00', 'unit_price' => '19.99', 'line_total' => '39.98'];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (OrderItem $item): void {
            $order = $item->order;
            $subtotal = $order->items()->get()->sum(fn (OrderItem $line): int => Money::minor($line->line_total));
            $order->update(['subtotal' => Money::decimal($subtotal),
                'total' => Money::decimal($subtotal + Money::minor($order->delivery_fee))]);
        });
    }
}
