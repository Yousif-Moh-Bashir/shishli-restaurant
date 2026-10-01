<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Order::query()->with('branch');
        if (isset($data['search'])) {
            $query->where(function (Builder $query) use ($data): void {
                $query->where('order_number', 'like', '%'.$data['search'].'%')
                    ->orWhere('customer_name', 'like', '%'.$data['search'].'%')
                    ->orWhere('customer_phone', 'like', '%'.$data['search'].'%');
            });
        }
        if (isset($data['branch'])) {
            $query->whereHas('branch', fn (Builder $query) => $query->where('uuid', $data['branch']));
        }
        foreach (['status', 'type', 'payment_status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['date_from'])) {
            $query->where('placed_at', '>=', $data['date_from'].' 00:00:00');
        }
        if (isset($data['date_to'])) {
            $query->where('placed_at', '<=', $data['date_to'].' 23:59:59');
        }

        return ApiResponse::paginated(OrderResource::collection($query->orderByDesc('placed_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))->withQueryString()));
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success(data: new OrderResource($order->load(['branch', 'items.options', 'address'])));
    }
}
