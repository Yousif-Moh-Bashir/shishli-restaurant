<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\PromotionType;
use App\Models\Order;
use App\Models\OrderPromotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderPromotion> */
class OrderPromotionFactory extends Factory
{
    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'promotion_uuid' => fake()->uuid(), 'promotion_name' => 'Historic promotion', 'promotion_type' => PromotionType::Automatic, 'discount_type' => DiscountType::Fixed, 'discount_value' => '10.00', 'coupon_code' => null, 'discount_amount' => '10.00'];
    }
}
