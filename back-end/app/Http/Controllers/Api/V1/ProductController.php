<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Products\ListProductsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListProductsRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(ListProductsRequest $request, ListProductsAction $listProducts): JsonResponse
    {
        return ApiResponse::paginated(ProductResource::collection($listProducts->handle($request->validated())));
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category');
        abort_unless($product->is_active && $product->category->is_active, 404);

        $product->load([
            'images',
            'optionGroups' => fn (BelongsToMany $groups): BelongsToMany => $groups->where('option_groups.is_active', true),
            'optionGroups.values' => fn (HasMany $values): HasMany => $values->where('is_active', true),
        ]);

        return ApiResponse::success(data: new ProductResource($product));
    }
}
