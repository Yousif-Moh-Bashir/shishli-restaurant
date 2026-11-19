<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerPushSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.fake()->uuid();

        return ['user_id' => User::factory(),
            'personal_access_token_id' => fn (array $attributes): int => User::findOrFail($attributes['user_id'])->createToken('api')->accessToken->id,
            'endpoint_hash' => hash('sha256', $endpoint), 'revision' => 1, 'consented_at' => now()->subHour(),
            'subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'B'.str_repeat('a', 86), 'auth' => str_repeat('b', 22)]]];
    }
}
