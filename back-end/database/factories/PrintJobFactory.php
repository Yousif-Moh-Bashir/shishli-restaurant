<?php

namespace Database\Factories;

use App\Models\OrderStatusHistory;
use App\Models\PrintJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintJob>
 */
class PrintJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'acceptance_id' => OrderStatusHistory::factory(),
            'order_id' => fn (array $attributes) => OrderStatusHistory::findOrFail($attributes['acceptance_id'])->order_id,
            'print_agent_id' => PrintAgentFactory::new(),
            'destination' => 'kitchen',
            'copy_key' => PrintJob::ORIGINAL_COPY,
            'status' => 'pending',
            'receipt' => ['version' => 1, 'order_number' => 'SIM-001', 'items' => []],
            'available_at' => now(),
        ];
    }
}
