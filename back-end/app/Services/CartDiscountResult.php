<?php

namespace App\Services;

use App\Enums\PromotionIssueCode;

readonly class CartDiscountResult
{
    /** @param array<int,PromotionResult> $appliedPromotions @param array<string,int> $itemDiscounts */
    public function __construct(public int $subtotalBeforeDiscount, public int $discountTotal, public int $subtotalAfterDiscount, public array $appliedPromotions, public array $itemDiscounts, public ?PromotionIssueCode $rejectedCoupon = null) {}
}
