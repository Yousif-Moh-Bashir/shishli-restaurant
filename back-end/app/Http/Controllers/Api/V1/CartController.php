<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\ChangeCartBranchAction;
use App\Actions\Cart\CreateCartAction;
use App\Actions\Cart\RepriceCartAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateCartRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Responses\ApiResponse;
use App\Services\CartResolver;
use App\Services\CartStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private CartResolver $resolver) {}

    public function store(CreateCartRequest $request, CreateCartAction $create): JsonResponse
    {
        $result = $create->handle($request->validated('branch_uuid'), $request->user());

        return ApiResponse::success(data: new CartResource($result->cart, $result->token), statusCode: $result->created ? 201 : 200);
    }

    public function show(Request $request, CartStateService $state): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($state->load($this->resolver->resolve($request))));
    }

    public function refresh(Request $request, RepriceCartAction $reprice): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($reprice->handle($this->resolver->resolve($request))));
    }

    public function update(CreateCartRequest $request, ChangeCartBranchAction $change): JsonResponse
    {
        return ApiResponse::success(data: new CartResource($change->handle($this->resolver->resolve($request), $request->validated('branch_uuid'))));
    }
}
