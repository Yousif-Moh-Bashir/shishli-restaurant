<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchBusinessHourFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory()->withoutBusinessHours(), 'day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '23:00'];
    }

    public function morning(): static
    {
        return $this->state(fn (): array => ['opens_at' => '11:00', 'closes_at' => '15:00']);
    }

    public function evening(): static
    {
        return $this->state(fn (): array => ['opens_at' => '17:00', 'closes_at' => '22:00']);
    }

    public function overnight(): static
    {
        return $this->state(fn (): array => ['opens_at' => '18:00', 'closes_at' => '03:00']);
    }
}
