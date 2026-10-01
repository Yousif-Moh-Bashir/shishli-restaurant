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
        if ($order->user_id === null) {
            $data['access_token'] = $order->access_token;
        }

        return ApiResponse::success(data: $data, statusCode: $order->wasRecentlyCreated ? 201 : 200);
    }
}
