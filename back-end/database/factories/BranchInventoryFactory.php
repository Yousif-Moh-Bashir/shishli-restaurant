<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchInventoryFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'product_id' => Product::factory()->state(['tracks_inventory' => true]), 'on_hand' => '20.000', 'reserved' => '0.000', 'low_stock_threshold' => '5.000', 'is_tracking' => true];
    }

    public function inStock(): static
    {
        return $this->state(fn (): array => ['on_hand' => '20.000', 'reserved' => '0.000']);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['on_hand' => '0.000', 'reserved' => '0.000']);
    }

    public function lowStock(): static
    {
        return $this->state(fn (): array => ['on_hand' => '5.000', 'reserved' => '1.000', 'low_stock_threshold' => '5.000']);
    }

    public function notTracking(): static
    {
        return $this->state(fn (): array => ['is_tracking' => false]);
    }
}
