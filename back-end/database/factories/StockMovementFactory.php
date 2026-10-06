<?php

namespace Database\Factories;

use App\Models\BranchInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_inventory_id' => BranchInventory::factory(), 'type' => 'stock_in', 'quantity' => '5.000',
            'on_hand_before' => '15.000', 'on_hand_after' => '20.000', 'reserved_before' => '0.000', 'reserved_after' => '0.000', 'reason' => 'استلام', 'created_at' => now()];
    }
}
