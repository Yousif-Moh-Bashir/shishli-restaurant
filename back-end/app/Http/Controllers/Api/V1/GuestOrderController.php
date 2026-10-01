<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\GuestOrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestOrderController extends Controller
{
    public function __invoke(Request $request, Order $order, GuestOrderAccess $access): JsonResponse
    {
        $access->authorize($order, $request->header('X-Order-Token'));

        return ApiResponse::success(data: new OrderResource($order->load(['branch', 'items.options', 'address'])));
    }
}
