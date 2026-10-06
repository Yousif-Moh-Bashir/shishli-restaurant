<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchClosureFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'reason' => 'صيانة', 'is_active' => true];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_active' => true]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    }

    public function future(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    }
}
