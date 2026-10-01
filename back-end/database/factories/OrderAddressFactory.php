<?php

namespace Database\Factories;

use App\Enums\OrderType;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderAddressFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory()->state(['type' => OrderType::Delivery]),
            'recipient_name' => fake()->name(), 'phone' => '0501234567', 'city' => 'الرياض', 'district' => 'المصيف',
            'street' => fake()->streetName(), 'building_number' => '12', 'delivery_zone_name' => 'المصيف'];
    }
}
