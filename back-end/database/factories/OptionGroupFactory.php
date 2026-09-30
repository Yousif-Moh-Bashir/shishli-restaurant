<?php

namespace Database\Factories;

use App\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OptionGroup> */
class OptionGroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'type' => 'single', 'is_required' => false, 'min_select' => 0, 'max_select' => 1];
    }

    public function single(): static
    {
        return $this->state(['type' => 'single', 'max_select' => 1, 'min_select' => 0, 'is_required' => false]);
    }

    public function multiple(): static
    {
        return $this->state(['type' => 'multiple', 'max_select' => null]);
    }

    public function required(): static
    {
        return $this->state(['is_required' => true, 'min_select' => 1]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
