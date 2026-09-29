<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => fake()->unique()->slug(),
            'phone' => '05'.fake()->numerify('########'),
            'whatsapp' => '05'.fake()->numerify('########'),
            'city' => 'الرياض',
            'district' => fake()->streetName(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'is_active' => true,
            'accepts_orders' => true,
            'sort_order' => 0,
        ];
    }
}
