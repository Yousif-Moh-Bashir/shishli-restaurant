<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Products\CreateProductAction;
use App\Actions\Products\ListAdminProductsAction;
use App\Actions\Products\UpdateProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListProductsRequest;
use App\Http\Requests\Api\V1\Admin\StoreProductRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request, ListAdminProductsAction $listProducts): JsonResponse
    {
        return ApiResponse::paginated(ProductResource::collection($listProducts->handle($request->validated())));
    }

    public function store(StoreProductRequest $request, CreateProductAction $createProduct): JsonResponse
    {
        $product = $createProduct->handle($request->validated())->load('category');

        return ApiResponse::success(data: new ProductResource($product), statusCode: 201);
    }

    public function show(Product $product): JsonResponse
    {
        return ApiResponse::success(data: new ProductResource($product->load(['category', 'primaryImage', 'images', 'optionGroups.values'])));
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductAction $updateProduct): JsonResponse
    {
        $product = $updateProduct->handle($product, $request->validated())->load('category');

        return ApiResponse::success(data: new ProductResource($product));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return ApiResponse::success(message: 'تم حذف المنتج بنجاح.');
    }
}
