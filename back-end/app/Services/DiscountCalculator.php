<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PromotionApplicationType;
use App\Models\Cart;
use App\Models\Promotion;

class DiscountCalculator
{
    public function __construct(private DiscountAllocationService $allocation) {}

    /** @param array<string,int> $remaining */
    public function calculate(Promotion $promotion, Cart $cart, array $remaining): DiscountResult
    {
        $weights = [];
        $discounts = [];
        $eligible = 0;
        foreach ($cart->items as $item) {
            if ($item->issues !== []) {
                continue;
            }
            $target = match ($promotion->application_type) {
                PromotionApplicationType::Cart => true,
                PromotionApplicationType::Product => $promotion->products->contains('id', $item->product_id),
                PromotionApplicationType::Category => $promotion->categories->contains('id', $item->product->category_id),
            };
            if (! $target) {
                continue;
            }
            $line = Money::minor($item->line_total);
            $base = Money::minor($item->base_price);
            $available = $remaining[$item->uuid] ?? 0;
            $capacity = min($available, $promotion->application_type === PromotionApplicationType::Cart ? $line : max(0, $base * $item->quantity - ($line - $available)));
            $weights[$item->uuid] = $capacity;
            $eligible += $capacity;
            if ($promotion->application_type !== PromotionApplicationType::Cart) {
                // Product/category fixed amounts apply per unit; option modifiers are excluded.
                $perUnit = $this->amount($promotion, $base);
                $discounts[$item->uuid] = min($capacity, $perUnit * $item->quantity);
            }
        }
        if ($promotion->application_type === PromotionApplicationType::Cart) {
            $discounts = $this->allocation->allocate(min($eligible, $this->amount($promotion, $eligible)), $weights);
        }
        $discount = array_sum($discounts);
        if ($promotion->maximum_discount !== null && $discount > Money::minor($promotion->maximum_discount)) {
            $discount = Money::minor($promotion->maximum_discount);
            $discounts = $this->allocation->allocate($discount, $discounts);
        }

        return new DiscountResult($discount, $eligible, $discounts);
    }

    private function amount(Promotion $promotion, int $base): int
    {
        $value = Money::minor($promotion->discount_value);

        return min($base, match ($promotion->discount_type) {
            DiscountType::Percentage => intdiv($base * $value + 5000, 10000),
            DiscountType::Fixed => $value,
            DiscountType::FixedPrice => max(0, $base - $value),
        });
    }
}
