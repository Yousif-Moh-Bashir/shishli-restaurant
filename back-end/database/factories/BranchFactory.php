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
    /** Existing commerce fixtures represent an operational branch. Real branches receive no invented schedule. */
    public function configure(): static
    {
        return $this->afterCreating(function (Branch $branch): void {
            $hours = [];
            for ($day = 1; $day <= 7; $day++) {
                $hours[] = ['day_of_week' => $day, 'opens_at' => '00:00', 'closes_at' => '12:00'];
                $hours[] = ['day_of_week' => $day, 'opens_at' => '12:00', 'closes_at' => '00:00'];
            }
            $branch->businessHours()->createMany($hours);
        });
    }

    public function withoutBusinessHours(): static
    {
        return $this->afterCreating(fn (Branch $branch) => $branch->businessHours()->delete());
    }

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
            'supports_pickup' => true,
            'supports_delivery' => true,
            'sort_order' => 0,
        ];
    }
}
