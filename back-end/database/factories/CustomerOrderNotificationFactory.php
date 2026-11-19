<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerOrderNotificationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'order_id' => fn (array $attributes): int => Order::factory()->create(['user_id' => $attributes['user_id']])->id,
            'order_status_history_id' => fn (array $attributes): int => OrderStatusHistory::factory()->create(['order_id' => $attributes['order_id'], 'to_status' => 'pending'])->id,
            'type' => 'order_placed', 'status' => 'pending', 'title' => 'استلمنا طلبك للمراجعة', 'body' => 'إرسال الطلب لا يعني قبوله.', 'occurred_at' => now()];
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()]);
    }
}
