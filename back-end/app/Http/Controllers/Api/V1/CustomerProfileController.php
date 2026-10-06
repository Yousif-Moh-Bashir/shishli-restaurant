<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\ClaimGuestOrdersAction;
use App\Actions\Customers\UpdateCustomerProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClaimGuestOrdersRequest;
use App\Http\Requests\Api\V1\UpdateCustomerProfileRequest;
use App\Http\Resources\Api\V1\CustomerProfileResource;
use App\Http\Responses\ApiResponse;
use App\Services\Customers\CustomerStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerProfileController extends Controller
{
    public function show(Request $request, CustomerStatisticsService $statistics): JsonResponse
    {
        return ApiResponse::success(data: new CustomerProfileResource($statistics->loadProfile($request->user())));
    }

    public function update(UpdateCustomerProfileRequest $request, UpdateCustomerProfileAction $update, CustomerStatisticsService $statistics): JsonResponse
    {
        return ApiResponse::success(data: new CustomerProfileResource($statistics->loadProfile($update->handle($request->user(), $request->validated()))));
    }

    public function claimOrders(ClaimGuestOrdersRequest $request, ClaimGuestOrdersAction $claim): JsonResponse
    {
        return ApiResponse::success(data: ['claimed_orders' => $claim->handle($request->user())]);
    }
}
