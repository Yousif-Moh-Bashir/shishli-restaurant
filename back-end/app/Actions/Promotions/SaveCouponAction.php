<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionType;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Services\PromotionConfigurationValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCouponAction
{
    public function __construct(private PromotionConfigurationValidator $configuration) {}

    public function handle(Promotion $promotion, array $data, ?Coupon $coupon = null): Coupon
    {
        return DB::transaction(function () use ($promotion, $data, $coupon): Coupon {
            $promotion = Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            if ($promotion->type !== PromotionType::Coupon) {
                throw ValidationException::withMessages(['promotion' => 'العرض لا يدعم الكوبونات.']);
            }
            if ($coupon === null) {
                $coupon = $promotion->coupons()->make($data);
                $this->configuration->validateSchedule($coupon);
                $coupon->save();

                return $coupon;
            }
            $coupon = $promotion->coupons()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $coupon->fill($data);
            $this->configuration->validateSchedule($coupon);
            $coupon->save();

            return $coupon;
        }, 3);
    }
}
