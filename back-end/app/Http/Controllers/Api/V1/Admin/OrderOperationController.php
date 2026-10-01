<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelOrderRequest;
use App\Http\Requests\Api\V1\OrderOperationRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\OrderOperationAuthorization;
use Illuminate\Http\JsonResponse;

class OrderOperationController extends Controller
{
    public function __construct(private TransitionOrderStatusAction $transition, private OrderOperationAuthorization $authorization) {}

    public function confirm(OrderOperationRequest $request, Order $order): JsonResponse
    {
        return $this->perform($request, $order, OrderStatus::Confirmed);
    }

    public function startPreparing(OrderOperationRequest $request, Order $order): JsonResponse
    {
        return $this->perform($request, $order, OrderStatus::Preparing);
    }

    public function markReady(OrderOperationRequest $request, Order $order): JsonResponse
    {
        return $this->perform($request, $order, OrderStatus::Ready);
    }

    public function dispatch(OrderOperationRequest $request, Order $order): JsonResponse
    {
        return $this->perform($request, $order, OrderStatus::OutForDelivery);
    }

    public function complete(OrderOperationRequest $request, Order $order): JsonResponse
    {
        return $this->perform($request, $order, OrderStatus::Completed);
    }

    public function cancel(CancelOrderRequest $request, Order $order, CancelOrderAction $cancel): JsonResponse
    {
        $result = $cancel->handle($order, $request->validated('reason'), $request->user(), $this->authorization->staffSource($request->user()));

        return ApiResponse::success(data: new OrderResource($result));
    }

    private function perform(OrderOperationRequest $request, Order $order, OrderStatus $target): JsonResponse
    {
        $result = $this->transition->handle($order, $target, $request->user(), $this->authorization->staffSource($request->user()), $request->validated('note'));

        return ApiResponse::success(data: new OrderResource($result));
    }
}
