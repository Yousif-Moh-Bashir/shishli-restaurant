<?php

namespace Database\Factories;

use App\Models\PrintJobAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintJobAttempt>
 */
class PrintJobAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'print_job_id' => PrintJobFactory::new(),
            'number' => 1,
            'status' => 'leased',
        ];
    }
}
