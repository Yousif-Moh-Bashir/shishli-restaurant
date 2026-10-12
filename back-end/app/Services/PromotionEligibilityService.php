<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PromotionIssueCode;
use App\Enums\PromotionType;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Promotion;

class PromotionEligibilityService
{
    public function evaluate(Promotion $promotion, Cart $cart, int $subtotal, ?Coupon $coupon = null, ?bool $hasPreviousOrder = null): ?PromotionIssueCode
    {
        $time = now()->startOfSecond();
        if ($promotion->type === PromotionType::Coupon && $coupon === null) {
            return PromotionIssueCode::NotApplicable;
        }
        if ($promotion->first_order_only) {
            if ($cart->user_id === null) {
                return PromotionIssueCode::AuthenticationRequired;
            }
            if ($hasPreviousOrder ?? Order::where('user_id', $cart->user_id)->where('status', '!=', OrderStatus::Cancelled)->exists()) {
                return PromotionIssueCode::FirstOrderOnly;
            }
            $used = $promotion->getAttribute('user_usage_count') ?? $promotion->usages()->where('user_id', $cart->user_id)->count();
            if ($used > 0) {
                return PromotionIssueCode::UserLimitReached;
            }
        }
        foreach (array_filter([$coupon, $promotion]) as $rule) {
            if ($rule->trashed() || ! $rule->is_active) {
                return PromotionIssueCode::Inactive;
            }
            if ($rule->starts_at?->gt($time)) {
                return PromotionIssueCode::NotStarted;
            }
            if ($rule->ends_at?->lt($time)) {
                return PromotionIssueCode::Expired;
            }
            if ($rule->usage_limit !== null && $rule->used_count >= $rule->usage_limit) {
                return PromotionIssueCode::UsageLimitReached;
            }
            if ($rule->usage_limit_per_user !== null) {
                if ($cart->user_id === null) {
                    return PromotionIssueCode::AuthenticationRequired;
                }
                $count = $rule->getAttribute('user_usage_count') ?? $rule->usages()->where('user_id', $cart->user_id)->count();
                if ($count >= $rule->usage_limit_per_user) {
                    return PromotionIssueCode::UserLimitReached;
                }
            }
        }
        if ($promotion->branches->isNotEmpty() && ! $promotion->branches->contains('id', $cart->branch_id)) {
            return PromotionIssueCode::WrongBranch;
        }
        if ($subtotal < Money::minor($promotion->minimum_subtotal)) {
            return PromotionIssueCode::MinimumNotMet;
        }

        return null;
    }
}
