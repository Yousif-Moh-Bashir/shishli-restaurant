<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TrackOrderRequest;
use App\Http\Resources\Api\V1\OrderTrackingResource;
use App\Http\Responses\ApiResponse;
use App\Services\OrderTrackingAccessService;
use App\Services\OrderTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    public function guest(TrackOrderRequest $request, OrderTrackingAccessService $access, OrderTrackingService $tracking): JsonResponse
    {
        return ApiResponse::success(data: new OrderTrackingResource($tracking->buildTrackingData(
            $access->resolveForGuest($request->validated('order_number'), $request->validated('token')))))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $order, OrderTrackingAccessService $access, OrderTrackingService $tracking): JsonResponse
    {
        return ApiResponse::success(data: new OrderTrackingResource($tracking->buildTrackingData($access->resolveForAuthenticatedUser($order, $request->user()))))
            ->header('Cache-Control', 'private, no-store');
    }
}
