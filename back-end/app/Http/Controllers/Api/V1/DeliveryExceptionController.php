<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Drivers\ManageDeliveryExceptionAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AdminOrderResource;
use App\Http\Resources\Api\V1\DriverOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryExceptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_uuid' => ['sometimes', 'uuid'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = Order::where('type', 'delivery')->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->where(function (Builder $query): void {
                $query->whereNotNull('delivery_exception_requested_at')
                    ->orWhereIn('delivery_status', ['failed', 'returning', 'returned', 'released'])
                    ->orWhere(function (Builder $query): void {
                        $query->whereNotNull('driver_id')->where('placed_at', '<=', now()->subHours(2));
                    });
            });
        if (isset($data['branch_uuid'])) {
            $query->whereHas('branch', fn (Builder $branch) => $branch->where('uuid', $data['branch_uuid']));
        }

        return ApiResponse::paginated(AdminOrderResource::collection($query->with(['branch', 'driver', 'payments', 'latestPayment', 'cashCollections.driver', 'deliveryHistory.actor', 'deliveryHistory.driver'])
            ->orderBy('placed_at')->orderBy('id')->paginate($data['per_page'] ?? 20)));
    }

    public function manage(Request $request, Order $order, ManageDeliveryExceptionAction $action): JsonResponse
    {
        $result = $action->handle($request->user(), $order, $request->only(['action', 'reason', 'operation_key', 'expected_revision', 'driver_uuid', 'acknowledged', 'custody_outcome']));

        return ApiResponse::success(new AdminOrderResource($result->load(['branch', 'driver', 'payments', 'latestPayment', 'cashCollections.driver', 'deliveryHistory.actor', 'deliveryHistory.driver'])));
    }

    public function intervention(Request $request, Order $order, ManageDeliveryExceptionAction $action): JsonResponse
    {
        $result = $action->handle($request->user(), $order, ['action' => 'request_intervention'] + $request->only(['reason', 'operation_key', 'expected_revision']), true);

        return ApiResponse::success(new DriverOrderResource($result->load(['branch', 'address', 'deliveryHistory.actor'])));
    }
}
