<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Customers\CustomerOrderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request, CustomerOrderQuery $orders): JsonResponse
    {
        return ApiResponse::paginated(OrderResource::collection($orders->apply($request->user()->orders()->getQuery(), $request->validated())
            ->paginate($request->integer('per_page', 15))->withQueryString()));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return ApiResponse::success(data: new OrderResource($order->load(['branch', 'items.options', 'address'])));
    }
}
