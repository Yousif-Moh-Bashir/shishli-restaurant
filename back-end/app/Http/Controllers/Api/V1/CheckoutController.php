<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\PlaceOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\CartResolver;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __invoke(CheckoutRequest $request, CartResolver $resolver, PlaceOrderAction $place): JsonResponse
    {
        $order = $place->handle($resolver->resolve($request, forCheckout: true), $request->validated());
        $data = (new OrderResource($order))->resolve($request);
        if ($order->user_id === null && $order->wasRecentlyCreated && $order->issuedGuestAccessToken !== null) {
            $data['access_token'] = $order->issuedGuestAccessToken;
            $data['tracking'] = ['order_number' => $order->order_number, 'token' => $order->issuedGuestAccessToken,
                'expires_at' => $order->guest_access_token_expires_at?->toISOString()];
        }

        return ApiResponse::success(data: $data, statusCode: $order->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
