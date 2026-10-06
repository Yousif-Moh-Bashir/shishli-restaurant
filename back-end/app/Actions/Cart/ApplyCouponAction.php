<?php

namespace App\Actions\Cart;

use App\Enums\PromotionIssueCode;
use App\Http\Responses\ApiResponse;
use App\Models\Cart;
use App\Models\Coupon;
use App\Services\CartMutation;
use App\Services\CartStateService;
use App\Services\PromotionEngine;
use Illuminate\Http\Exceptions\HttpResponseException;

class ApplyCouponAction
{
    public function __construct(private RepriceCartAction $reprice, private CartMutation $mutation, private CartStateService $state, private PromotionEngine $engine) {}

    public function handle(Cart $cart, string $code): Cart
    {
        $cart = $this->reprice->handle($cart);

        return $this->mutation->run($cart, function (Cart $cart) use ($code): void {
            $coupon = Coupon::where('code', Coupon::normalizeCode($code))->first();
            if ($coupon === null) {
                throw new HttpResponseException(ApiResponse::error('الكوبون غير صالح.', ['coupon' => [PromotionIssueCode::NotFound->issue()]], 422));
            }
            $cart->coupon_id = $coupon->id;
            $cart->setRelation('coupon', $coupon);
            $this->state->load($cart);
            $result = $this->engine->calculate($cart);
            if ($result->rejectedCoupon !== null) {
                throw new HttpResponseException(ApiResponse::error('الكوبون غير صالح.', ['coupon' => [$result->rejectedCoupon->issue()]], 422));
            }
            $cart->save();
        });
    }
}
