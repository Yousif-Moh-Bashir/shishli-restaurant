<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\AddCartItemAction;
use App\Actions\Cart\ClearCartAction;
use App\Actions\Cart\RemoveCartItemAction;
use App\Actions\Cart\UpdateCartItemOptionsAction;
use App\Actions\Cart\UpdateCartItemQuantityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\UpdateCartItemOptionsRequest;
use App\Http\Requests\Api\V1\UpdateCartItemQuantityRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Responses\ApiResponse;
use App\Services\CartResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartItemController extends Controller
{
    public function __construct(private CartResolver $resolver) {}

    public function store(AddCartItemRequest $request, AddCartItemAction $add): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($add->handle($this->resolver->resolve($request), $request->validated())), statusCode: 201);
    }

    public function update(UpdateCartItemQuantityRequest $request, string $cartItem, UpdateCartItemQuantityAction $update): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($update->handle($this->resolver->resolve($request), $cartItem, $request->integer('quantity'))));
    }

    public function options(UpdateCartItemOptionsRequest $request, string $cartItem, UpdateCartItemOptionsAction $update): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($update->handle($this->resolver->resolve($request), $cartItem, $request->validated('options'))));
    }

    public function destroy(Request $request, string $cartItem, RemoveCartItemAction $remove): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($remove->handle($this->resolver->resolve($request), $cartItem)));
    }

    public function clear(Request $request, ClearCartAction $clear): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($clear->handle($this->resolver->resolve($request))));
    }
}
