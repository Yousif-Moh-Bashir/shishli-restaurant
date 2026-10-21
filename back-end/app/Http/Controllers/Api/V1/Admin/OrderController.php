<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminOrdersRequest;
use App\Http\Resources\Api\V1\AdminOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Admin\AdminOrderFilters;
use App\Services\Admin\AdminOrderQuery;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function index(AdminOrdersRequest $request, AdminOrderQuery $orders): JsonResponse
    {
        $data = $request->validated();
        $query = $orders->query(AdminOrderFilters::fromArray($data))->with(['branch', 'payments', 'driver']);

        return ApiResponse::paginated(AdminOrderResource::collection($query->orderBy($data['sort'] ?? 'placed_at', $data['direction'] ?? 'desc')->orderByDesc('id')->paginate($request->integer('per_page', 15))->withQueryString()));
    }

    public function counts(AdminOrdersRequest $request, AdminOrderQuery $orders): JsonResponse
    {
        return ApiResponse::success(data: $orders->counts(AdminOrderFilters::fromArray($request->validated())));
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success(data: new AdminOrderResource($order->load(['branch', 'items.options', 'address', 'payments', 'latestPayment', 'statusHistory.changedBy', 'cashCollections.driver', 'driver', 'deliveryHistory.actor', 'deliveryHistory.driver'])));
    }
}
