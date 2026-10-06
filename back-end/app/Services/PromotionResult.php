<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Promotion;

readonly class PromotionResult
{
    public string $promotionUuid;

    public string $promotionName;

    public ?string $couponUuid;

    public ?string $couponCode;

    public int $priority;

    public function __construct(public Promotion $promotion, public ?Coupon $coupon, public DiscountResult $discount)
    {
        $this->promotionUuid = $promotion->uuid;
        $this->promotionName = $promotion->name;
        $this->couponUuid = $coupon?->uuid;
        $this->couponCode = $coupon?->code;
        $this->priority = $promotion->priority;
    }

    public function summary(): array
    {
        return ['id' => $this->promotionUuid, 'name' => $this->promotionName, 'discount' => Money::decimal($this->discount->discountAmount)];
    }
}
