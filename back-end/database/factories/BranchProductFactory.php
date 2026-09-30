<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchProduct> */
class BranchProductFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'product_id' => Product::factory(), 'price_override' => null, 'is_available' => true];
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }

    public function withPriceOverride(string $price = '22.00'): static
    {
        return $this->state(['price_override' => $price]);
    }
}
