<?php

namespace Database\Factories;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemOptionFactory extends Factory
{
    public function definition(): array
    {
        return ['order_item_id' => OrderItem::factory(), 'option_group_uuid' => fake()->uuid(), 'option_group_name' => 'Size',
            'option_value_uuid' => fake()->uuid(), 'option_value_name' => 'Regular', 'price_modifier' => '0.00'];
    }
}
