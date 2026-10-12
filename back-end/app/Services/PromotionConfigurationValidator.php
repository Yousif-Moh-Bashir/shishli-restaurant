<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PromotionApplicationType;
use App\Enums\PromotionType;
use App\Models\Coupon;
use App\Models\Promotion;
use Illuminate\Validation\ValidationException;

class PromotionConfigurationValidator
{
    /** Recheck the effective state after acquiring the row lock, including concurrent admin edits. */
    public function validate(Promotion $promotion): void
    {
        if ($promotion->is_registration_offer && (! $promotion->first_order_only || $promotion->type !== PromotionType::Automatic || $promotion->application_type !== PromotionApplicationType::Cart || $promotion->discount_type !== DiscountType::Percentage)) {
            throw ValidationException::withMessages(['is_registration_offer' => 'عرض التسجيل يجب أن يكون خصمًا تلقائيًا بنسبة مئوية على السلة، لأول طلب فقط.']);
        }
        $value = Money::minor($promotion->discount_value);
        if ($promotion->is_registration_offer && $promotion->maximum_discount !== null && Money::minor($promotion->maximum_discount) <= 0) {
            throw ValidationException::withMessages(['maximum_discount' => 'حدد سقف خصم أكبر من صفر، أو اترك الحقل فارغًا.']);
        }
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
