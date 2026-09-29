<?php

namespace Database\Factories;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OptionValue> */
class OptionValueFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['option_group_id' => OptionGroup::factory(), 'name' => fake()->word()];
    }
}
