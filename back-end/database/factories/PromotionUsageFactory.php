<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionUsage> */
class PromotionUsageFactory extends Factory
{
    public function definition(): array
    {
        return ['promotion_id' => Promotion::factory(), 'coupon_id' => null, 'order_id' => Order::factory(), 'user_id' => null, 'discount_amount' => '10.00', 'used_at' => now()];
    }
}
