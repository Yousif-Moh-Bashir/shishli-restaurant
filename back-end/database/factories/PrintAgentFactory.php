<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\OrderStatusHistory;
use App\Models\PrintAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintAgent>
 */
class PrintAgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Simulation POS',
            'token_hash' => hash('sha256', 'spa_'.bin2hex(random_bytes(32))),
            'enabled' => true,
            'mode' => 'preview',
            'activation_history_id' => OrderStatusHistory::max('id') ?? 0,
            'activated_at' => now()->startOfSecond(),
            'expires_at' => now()->addDays(90),
        ];
    }
}
