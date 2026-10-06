<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Promotions\SaveCouponAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminListRequest;
use App\Http\Requests\Api\V1\CouponRequest;
use App\Http\Resources\Api\V1\CouponResource;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Services\Admin\AdminListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    public function index(AdminListRequest $request, Promotion $promotion, AdminListQuery $lists): JsonResponse
    {
        return ApiResponse::paginated(CouponResource::collection($lists->apply($promotion->coupons()->getQuery(), $request->validated(), ['code'], ['code', 'created_at', 'starts_at', 'ends_at', 'used_count'], 'code')->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function store(CouponRequest $request, Promotion $promotion, SaveCouponAction $save): JsonResponse
    {
        return ApiResponse::success(data: new CouponResource($save->handle($promotion, $request->validated())), statusCode: 201);
    }

    public function update(CouponRequest $request, Promotion $promotion, Coupon $coupon, SaveCouponAction $save): JsonResponse
    {
        return ApiResponse::success(data: new CouponResource($save->handle($promotion, $request->validated(), $coupon)));
    }

    public function destroy(Promotion $promotion, Coupon $coupon): JsonResponse
    {
        DB::transaction(function () use ($promotion, $coupon): void {
            $promotion = Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $promotion->coupons()->whereKey($coupon->id)->lockForUpdate()->firstOrFail()->delete();
        }, 3);

        return ApiResponse::success(message: 'تم حذف الكوبون.');
    }
}
