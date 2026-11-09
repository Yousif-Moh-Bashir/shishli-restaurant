<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\StaffOrderNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffOrderNotification> */
class StaffOrderNotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->branch_id,
            'activated_at' => now()->subMinute(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
