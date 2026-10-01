<?php

namespace Database\Factories;

use App\Enums\OrderStatusSource;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'from_status' => null,
            'to_status' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->status,
            'changed_by' => null, 'source' => OrderStatusSource::System, 'note' => null, 'metadata' => null];
    }
}
