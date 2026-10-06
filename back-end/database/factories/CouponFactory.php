<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return ['promotion_id' => Promotion::factory()->coupon(), 'code' => strtoupper(fake()->unique()->bothify('PROMO########')), 'is_active' => true];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function expired(): static
    {
        return $this->state(['ends_at' => now()->subDay()]);
    }

    public function exhausted(): static
    {
        return $this->state(['usage_limit' => 1])->afterCreating(function (Coupon $coupon): void {
            $coupon->forceFill(['used_count' => 1])->save();
        });
    }
}
