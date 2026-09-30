<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CartDeliveryQuoteRequest;
use App\Http\Resources\Api\V1\DeliveryQuoteResource;
use App\Http\Responses\ApiResponse;
use App\Services\CartResolver;
use App\Services\DeliveryAddressData;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;

class CartDeliveryQuoteController extends Controller
{
    public function __invoke(CartDeliveryQuoteRequest $request, CartResolver $resolver, DeliveryService $delivery): JsonResponse
    {
        $cart = $resolver->resolve($request)->load('branch');
        $data = $request->validated();
        if (isset($data['address_uuid'])) {
            abort_unless($request->user() !== null, 404);
            $address = $request->user()->addresses()->where('uuid', $data['address_uuid'])->firstOrFail();
            $address = DeliveryAddressData::fromAddress($address);
        } else {
            $address = DeliveryAddressData::fromArray($data['address']);
        }

        return ApiResponse::success(data: new DeliveryQuoteResource($delivery->calculateDelivery($cart->branch, $address, $cart->subtotal)));
    }
}
