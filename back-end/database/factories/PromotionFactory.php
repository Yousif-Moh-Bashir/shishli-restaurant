<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\PromotionApplicationType;
use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'slug' => fake()->unique()->slug().'_'.fake()->uuid(), 'type' => PromotionType::Automatic, 'application_type' => PromotionApplicationType::Cart, 'discount_type' => DiscountType::Percentage, 'discount_value' => '20.00', 'minimum_subtotal' => '0.00', 'is_active' => true, 'priority' => 0, 'is_stackable' => false];
    }

    public function automatic(): static
    {
        return $this->state(['type' => PromotionType::Automatic]);
    }

    public function coupon(): static
    {
        return $this->state(['type' => PromotionType::Coupon]);
    }

    public function percentage(): static
    {
        return $this->state(['discount_type' => DiscountType::Percentage, 'discount_value' => '20.00']);
    }

    public function fixed(): static
    {
        return $this->state(['discount_type' => DiscountType::Fixed, 'discount_value' => '10.00']);
    }

    public function fixedPrice(): static
    {
        return $this->state(['application_type' => PromotionApplicationType::Product, 'discount_type' => DiscountType::FixedPrice, 'discount_value' => '24.00']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(['ends_at' => now()->subDay()]);
    }

    public function future(): static
    {
        return $this->state(['starts_at' => now()->addDay()]);
    }

    public function stackable(): static
    {
        return $this->state(['is_stackable' => true]);
    }
}
