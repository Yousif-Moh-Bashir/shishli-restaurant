<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStaffPushSubscriptionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\StaffPushSubscription;
use App\Services\Notifications\StaffOrderNotifications;
use App\Services\Notifications\StaffPushDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class StaffNotificationController extends Controller
{
    public function index(Request $request, StaffOrderNotifications $notifications): JsonResponse
    {
        abort_unless($notifications->eligible($request->user()), 403);

        return ApiResponse::success(data: [
            ...$notifications->feed(),
            'push_diagnostic' => app(StaffPushDiagnostics::class)->capabilities($request),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreStaffPushSubscriptionRequest $request, StaffOrderNotifications $notifications): JsonResponse
    {
        abort_unless($notifications->channelEnabled('push'), 409);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);
        $data = $request->validated();
        $subscription = DB::transaction(function () use ($data, $request, $token): StaffPushSubscription {
            $hash = hash('sha256', $data['endpoint']);
            $subscription = StaffPushSubscription::firstOrCreate(['endpoint_hash' => $hash], [
                'user_id' => $request->user()->id, 'personal_access_token_id' => $token->id, 'subscription' => $data,
            ]);
            $subscription = StaffPushSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->user_id === $request->user()->id, 409);
            $subscription->update(['personal_access_token_id' => $token->id, 'subscription' => $data]);

            return $subscription;
        });

        return ApiResponse::success(data: ['id' => $subscription->uuid])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $subscription): JsonResponse
    {
        StaffPushSubscription::where('uuid', $subscription)->where('user_id', $request->user()->id)->firstOrFail()->delete();

        return ApiResponse::success(data: null);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        StaffPushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(data: null);
    }
}
