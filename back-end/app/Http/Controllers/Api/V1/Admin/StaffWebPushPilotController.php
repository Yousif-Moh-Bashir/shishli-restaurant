<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Notifications\StaffPushEndpoints;
use App\Services\Notifications\StaffWebPushPilot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffWebPushPilotController extends Controller
{
    public function index(Request $request, StaffWebPushPilot $pilot): JsonResponse
    {
        abort_unless($pilot->allowsRequest($request), 404);
        $record = $pilot->record();
        $subscription = $record !== null && $record['token_id'] === $request->user()->currentAccessToken()->id
            && $pilot->target($record['id']) !== null ? ['id' => $record['id']] : null;

        return ApiResponse::success(data: ['public_key' => config('staff_web_push_pilot.vapid.public_key'),
            'subscription' => $subscription, 'expires_at' => $pilot->expiresAt()->toISOString()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, StaffWebPushPilot $pilot): JsonResponse
    {
        abort_unless($pilot->allowsRequest($request), 404);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(StaffPushEndpoints::class)->valid($value)) {
                    $fail('Unsupported push endpoint.');
                }
            }],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'regex:/^B[A-Za-z0-9_-]{86}$/D'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}$/D'],
        ]);

        return ApiResponse::success(data: $pilot->register($request->user(), $request->user()->currentAccessToken(), $data))
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $subscription, StaffWebPushPilot $pilot): JsonResponse
    {
        abort_unless($pilot->allowsRequest($request), 404);
        $pilot->remove($subscription, $request->user()->currentAccessToken());

        return ApiResponse::success(data: null)->header('Cache-Control', 'private, no-store');
    }
}
