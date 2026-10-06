<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Notifications\RetryNotificationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListNotificationsRequest;
use App\Http\Resources\Api\V1\NotificationAttemptResource;
use App\Http\Resources\Api\V1\OutboundNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\OutboundNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = OutboundNotification::query();
        foreach (['status', 'channel', 'type', 'provider', 'reference_uuid'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['recipient']) || isset($data['search'])) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['recipient'] ?? $data['search']);
            $query->whereRaw("recipient LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }
        if (isset($data['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($data['date_from'])->startOfDay());
        }
        if (isset($data['date_to'])) {
            $query->where('created_at', '<', Carbon::parse($data['date_to'])->addDay()->startOfDay());
        }

        return ApiResponse::paginated(OutboundNotificationResource::collection($query->orderBy($data['sort'] ?? 'created_at', $data['direction'] ?? 'desc')->orderByDesc('id')->paginate($data['per_page'] ?? 20)->withQueryString()));
    }

    public function show(OutboundNotification $notification): JsonResponse
    {
        return ApiResponse::success(data: new OutboundNotificationResource($notification));
    }

    public function attempts(OutboundNotification $notification): JsonResponse
    {
        return ApiResponse::paginated(NotificationAttemptResource::collection($notification->attemptLogs()->paginate(20)));
    }

    public function retry(OutboundNotification $notification, RetryNotificationAction $retry): JsonResponse
    {
        return ApiResponse::success(data: new OutboundNotificationResource($retry->handle($notification)->fresh()), statusCode: 202);
    }
}
