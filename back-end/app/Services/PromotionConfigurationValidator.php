<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PromotionApplicationType;
use App\Models\Coupon;
use App\Models\Promotion;
use Illuminate\Validation\ValidationException;

class PromotionConfigurationValidator
{
    /** Recheck the effective state after acquiring the row lock, including concurrent admin edits. */
    public function validate(Promotion $promotion): void
    {
        $value = Money::minor($promotion->discount_value);
        if (($promotion->discount_type === DiscountType::Percentage && ($value <= 0 || $value > 10000)) || ($promotion->discount_type === DiscountType::Fixed && $value <= 0)) {
            throw ValidationException::withMessages(['discount_value' => 'قيمة الخصم غير صالحة.']);
        }
        if ($promotion->application_type === PromotionApplicationType::Cart && $promotion->discount_type === DiscountType::FixedPrice) {
            throw ValidationException::withMessages(['discount_type' => 'السعر الخاص يتطلب منتجات أو أقسام.']);
        }
        $this->validateSchedule($promotion);
        if (($promotion->application_type === PromotionApplicationType::Product && ! $promotion->products()->exists()) || ($promotion->application_type === PromotionApplicationType::Category && ! $promotion->categories()->exists())) {
            throw ValidationException::withMessages(['targets' => 'يجب تحديد أهداف العرض.']);
        }
    }

    public function validateSchedule(Promotion|Coupon $model): void
    {
        if ($model->starts_at !== null && $model->ends_at !== null && $model->ends_at->lte($model->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => 'يجب أن تكون النهاية بعد البداية.']);
        }
    }
}
