<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\ApplyCouponAction;
use App\Actions\Cart\RemoveCouponAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplyCouponRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Responses\ApiResponse;
use App\Services\CartResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartCouponController extends Controller
{
    public function store(ApplyCouponRequest $request, CartResolver $resolver, ApplyCouponAction $apply): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($apply->handle($resolver->resolve($request), $request->validated('code'))));
    }

    public function destroy(Request $request, CartResolver $resolver, RemoveCouponAction $remove): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($remove->handle($resolver->resolve($request))));
    }
}
