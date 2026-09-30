<?php

namespace Database\Factories;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'label' => 'المنزل', 'recipient_name' => fake()->name(), 'phone' => '0501234567',
            'city' => 'الرياض', 'district' => 'المصيف', 'street' => fake()->streetName(), 'latitude' => null, 'longitude' => null, 'is_default' => false];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function withCoordinates(): static
    {
        return $this->state(['latitude' => '24.7000000', 'longitude' => '46.7000000']);
    }

    public function withoutCoordinates(): static
    {
        return $this->state(['latitude' => null, 'longitude' => null]);
    }

    public function forUser(User $user): static
    {
        return $this->for($user);
    }
}
