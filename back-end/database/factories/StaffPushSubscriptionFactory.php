<?php

namespace Database\Factories;

use App\Models\StaffPushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffPushSubscription> */
class StaffPushSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.fake()->uuid();

        return [
            'user_id' => User::factory(),
            'personal_access_token_id' => fn (array $attributes) => User::findOrFail($attributes['user_id'])->createToken('api')->accessToken->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'B'.str_repeat('a', 86), 'auth' => str_repeat('a', 22)]],
        ];
    }
}
