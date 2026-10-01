<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\KitchenOrdersRequest;
use App\Http\Resources\Api\V1\KitchenOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class KitchenOrderController extends Controller
{
    public function __invoke(KitchenOrdersRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Order::query()->with(['branch', 'items.options']);
        $query->whereIn('status', isset($data['status']) ? [$data['status']] : [OrderStatus::Confirmed, OrderStatus::Preparing]);
        if (isset($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (isset($data['branch'])) {
            $query->whereHas('branch', fn (Builder $query) => $query->where('uuid', $data['branch']));
        }

        return ApiResponse::paginated(KitchenOrderResource::collection($query->orderBy('placed_at')->orderBy('id')
            ->paginate($request->integer('per_page', 25))->withQueryString()));
    }
}
