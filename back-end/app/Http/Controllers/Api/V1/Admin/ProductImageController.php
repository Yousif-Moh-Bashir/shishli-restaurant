<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Products\DeleteProductImageAction;
use App\Actions\Products\ReorderProductImagesAction;
use App\Actions\Products\SetPrimaryProductImageAction;
use App\Actions\Products\UploadProductImagesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderProductImagesRequest;
use App\Http\Requests\Api\V1\Admin\StoreProductImageRequest;
use App\Http\Resources\Api\V1\ProductImageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;

class ProductImageController extends Controller
{
    public function store(StoreProductImageRequest $request, Product $product, UploadProductImagesAction $upload): JsonResponse
    {
        $data = $request->validated();
        $images = $upload->handle($product, $data['images'], $data['alt_text'] ?? null, (bool) ($data['is_primary'] ?? false));

        return ApiResponse::success(data: ProductImageResource::collection($images), statusCode: 201);
    }

    public function destroy(Product $product, ProductImage $image, DeleteProductImageAction $delete): JsonResponse
    {
        $delete->handle($product, $image);

        return ApiResponse::success(message: 'تم حذف الصورة بنجاح.');
    }

    public function setPrimary(Product $product, ProductImage $image, SetPrimaryProductImageAction $setPrimary): JsonResponse
    {
        return ApiResponse::success(data: new ProductImageResource($setPrimary->handle($product, $image)));
    }

    public function reorder(ReorderProductImagesRequest $request, Product $product, ReorderProductImagesAction $reorder): JsonResponse
    {
        $images = $reorder->handle($product, $request->validated('images'));

        return ApiResponse::success(data: ProductImageResource::collection($images));
    }
}
