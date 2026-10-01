<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use App\Http\Resources\Api\V1\OrderStatusHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderHistoryController extends Controller
{
    public function __invoke(ListOrdersRequest $request, Order $order): JsonResponse
    {
        return ApiResponse::paginated(OrderStatusHistoryResource::collection($order->statusHistory()->with('changedBy')
            ->paginate($request->integer('per_page', 50))));
    }
}
