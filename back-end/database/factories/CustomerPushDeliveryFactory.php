<?php

namespace Database\Factories;

use App\Models\CustomerOrderNotification;
use App\Models\CustomerPushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerPushDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['notification_id' => CustomerOrderNotification::factory()->published(),
            'subscription_id' => fn (array $attributes): int => CustomerPushSubscription::factory()->create([
                'user_id' => CustomerOrderNotification::findOrFail($attributes['notification_id'])->user_id])->id,
            'device_uuid' => fn (array $attributes): string => CustomerPushSubscription::findOrFail($attributes['subscription_id'])->uuid,
            'subscription_revision' => 1, 'activated_at' => config('customer_push.starts_at'), 'expires_at' => now()->addMinutes(10)];
    }
}
