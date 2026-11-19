<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerOrderNotification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerNotificationController extends Controller
{
    private function visible(Request $request): Builder
    {
        abort_unless(User::whereKey($request->user()->id)->where('is_active', true)->exists(), 401);

        return CustomerOrderNotification::visibleTo($request->user());
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = $this->visible($request);
        $unread = (clone $query)->whereNull('read_at')->count();
        $rows = $query->with('order')->orderByDesc('occurred_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return CustomerNotificationResource::collection($rows)
            ->additional(['success' => true, 'message' => 'Success', 'errors' => null, 'unread_count' => $unread])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $notification): JsonResponse
    {
        $row = $this->visible($request)->where('uuid', $notification)->with('order')->firstOrFail();

        return ApiResponse::success(new CustomerNotificationResource($row))->header('Cache-Control', 'private, no-store');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread_count' => $this->visible($request)->whereNull('read_at')->count()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $row = $this->visible($request)->where('uuid', $notification)->firstOrFail();
        $row = DB::transaction(function () use ($request, $row): CustomerOrderNotification {
            $order = Order::whereKey($row->order_id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            $locked = CustomerOrderNotification::whereKey($row->id)->where('user_id', $request->user()->id)
                ->whereNotNull('published_at')->lockForUpdate()->firstOrFail();
            CustomerOrderNotification::whereKey($locked->id)->whereNull('read_at')->update(['read_at' => now()]);

            return $locked->fresh()->setRelation('order', $order);
        }, 3);

        return ApiResponse::success(new CustomerNotificationResource($row))
            ->header('Cache-Control', 'private, no-store');
    }
}
