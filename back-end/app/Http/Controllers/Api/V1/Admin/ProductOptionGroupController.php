<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Options\AttachProductOptionGroupAction;
use App\Actions\Options\DetachProductOptionGroupAction;
use App\Actions\Options\UpdateProductOptionGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AttachProductOptionGroupRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductOptionGroupRequest;
use App\Http\Resources\Api\V1\ProductOptionGroupResource;
use App\Http\Responses\ApiResponse;
use App\Models\OptionGroup;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductOptionGroupController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return ApiResponse::success(data: ProductOptionGroupResource::collection($product->optionGroups()->with('values')->get()));
    }

    public function store(AttachProductOptionGroupRequest $request, Product $product, AttachProductOptionGroupAction $attach): JsonResponse
    {
        return ApiResponse::success(data: new ProductOptionGroupResource($attach->handle($product, $request->validated())), statusCode: 201);
    }

    public function update(UpdateProductOptionGroupRequest $request, Product $product, OptionGroup $optionGroup, UpdateProductOptionGroupAction $update): JsonResponse
    {
        return ApiResponse::success(data: new ProductOptionGroupResource($update->handle($product, $optionGroup, $request->validated())));
    }

    public function destroy(Product $product, OptionGroup $optionGroup, DetachProductOptionGroupAction $detach): JsonResponse
    {
        $detach->handle($product, $optionGroup);

        return ApiResponse::success(message: 'تم فك ربط مجموعة الخيارات بنجاح.');
    }
}
