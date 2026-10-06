<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Products\BulkProductUpdateAction;
use App\Actions\Products\CreateProductAction;
use App\Actions\Products\ListAdminProductsAction;
use App\Actions\Products\UpdateProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BulkProductStatusRequest;
use App\Http\Requests\Api\V1\Admin\ListProductsRequest;
use App\Http\Requests\Api\V1\Admin\StoreProductRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function bulk(BulkProductStatusRequest $request, BulkProductUpdateAction $bulk): JsonResponse
    {
        return ApiResponse::success(data: ['updated_count' => $bulk->handle($request->user(), $request->validated('product_uuids'), $request->field(), (bool) $request->validated($request->field()))]);
    }

    public function index(ListProductsRequest $request, ListAdminProductsAction $listProducts): JsonResponse
    {
        return ApiResponse::paginated(ProductResource::collection($listProducts->handle($request->validated())));
    }

    public function store(StoreProductRequest $request, CreateProductAction $createProduct): JsonResponse
    {
        $product = $createProduct->handle($request->validated())->load('category');

        return ApiResponse::success(data: new ProductResource($product), statusCode: 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $relations = ['category', 'primaryImage', 'images', 'optionGroups.values', 'branches'];
        if ($request->user()->can('inventory.view')) {
            $relations[] = 'inventories.branch';
        }

        return ApiResponse::success(data: new ProductResource($product->load($relations)));
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
