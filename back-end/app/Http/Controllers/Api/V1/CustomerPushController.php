<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCustomerPushRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerPushSubscription;
use App\Services\Notifications\CustomerPushNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class CustomerPushController extends Controller
{
    public function index(Request $request, CustomerPushNotifications $push): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);

        return ApiResponse::success(['enabled' => $push->activation() !== null,
            'public_key' => $push->activation() === null ? null : config('customer_push.public_key'),
            'devices' => CustomerPushSubscription::where('user_id', $request->user()->id)->orderByDesc('id')->get()
                ->map(fn ($device) => ['id' => $device->uuid, 'created_at' => $device->created_at->toISOString()])->all(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreCustomerPushRequest $request, CustomerPushNotifications $push): JsonResponse
    {
        abort_unless($push->activation() !== null, 409);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);
        $data = $request->validated();
        $row = DB::transaction(function () use ($request, $token, $data): CustomerPushSubscription {
            $row = CustomerPushSubscription::firstOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
                'user_id' => $request->user()->id, 'personal_access_token_id' => $token->id, 'subscription' => $data, 'consented_at' => now(),
            ]);
            $row = CustomerPushSubscription::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $request->user()->id, 409, 'أعد تفعيل إشعارات هذا الجهاز لحسابك الحالي.');
            $row->update(['subscription' => $data, 'personal_access_token_id' => $token->id,
                'revision' => $row->revision + ($row->subscription !== $data ? 1 : 0)]);

            return $row;
        }, 3);

        return ApiResponse::success(['id' => $row->uuid])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $subscription): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);
        CustomerPushSubscription::where('uuid', $subscription)->where('user_id', $request->user()->id)->firstOrFail()->delete();

        return ApiResponse::success(null)->header('Cache-Control', 'private, no-store');
    }
}
