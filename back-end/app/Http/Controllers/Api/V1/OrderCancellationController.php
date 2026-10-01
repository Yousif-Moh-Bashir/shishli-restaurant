<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CancelOrderAction;
use App\Enums\OrderStatusSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\GuestOrderAccess;
use Illuminate\Http\JsonResponse;

class OrderCancellationController extends Controller
{
    public function authenticated(CancelOrderRequest $request, Order $order, CancelOrderAction $cancel): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $result = $cancel->handle($order, $request->validated('reason'), $request->user(), OrderStatusSource::Customer);

        return ApiResponse::success(data: new OrderResource($result));
    }

    public function guest(CancelOrderRequest $request, Order $order, CancelOrderAction $cancel, GuestOrderAccess $access): JsonResponse
    {
        $access->authorize($order, $request->header('X-Order-Token'));
        $result = $cancel->handle($order, $request->validated('reason'), null, OrderStatusSource::Customer, $request->header('X-Order-Token'));

        return ApiResponse::success(data: new OrderResource($result));
    }
}
