<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStaffPushSubscriptionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Notifications\StaffPushDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class StaffPushDiagnosticController extends Controller
{
    public function index(Request $request, StaffPushDiagnostics $diagnostics): JsonResponse
    {
        abort_unless($diagnostics->allowsRequest($request), 404);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);

        return ApiResponse::success(data: [
            ...$diagnostics->capabilities($request),
            'subscription' => $diagnostics->forSession($request->user(), $token),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreStaffPushSubscriptionRequest $request, StaffPushDiagnostics $diagnostics): JsonResponse
    {
        abort_unless($diagnostics->allowsRequest($request), 404);
        abort_unless($diagnostics->ready(), 409);
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);

        return ApiResponse::success(data: $diagnostics->register($request->user(), $token, $request->validated()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $subscription, StaffPushDiagnostics $diagnostics): JsonResponse
    {
        abort_unless($diagnostics->allowsRequest($request), 404);
        $diagnostics->remove($subscription, $request->user()->id);

        return ApiResponse::success(data: null);
    }
}
