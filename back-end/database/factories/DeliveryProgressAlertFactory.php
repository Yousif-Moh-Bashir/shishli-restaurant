<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryProgressAlertFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory()->delivery(), 'driver_id' => User::factory(),
            'stage' => 'assigned', 'progress_key' => hash('sha256', fake()->uuid()),
            'last_progress_at' => now()->subMinutes(15), 'reminded_at' => now()];
    }
}
