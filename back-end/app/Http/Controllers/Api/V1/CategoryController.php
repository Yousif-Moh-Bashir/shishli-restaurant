<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Categories\CreateCategoryAction;
use App\Actions\Categories\DeleteCategoryAction;
use App\Actions\Categories\UpdateCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::where('is_active', true)->withCount(['products' => fn (Builder $products): Builder => $products->where('is_active', true)])->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success(data: CategoryResource::collection($categories));
    }

    public function adminIndex(): JsonResponse
    {
        $categories = Category::withCount('products')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success(data: CategoryResource::collection($categories));
    }

    public function store(StoreCategoryRequest $request, CreateCategoryAction $createCategory): JsonResponse
    {
        $category = $createCategory->handle($request->validated());

        return ApiResponse::success(data: new CategoryResource($category), statusCode: JsonResponse::HTTP_CREATED);
    }

    public function show(Category $category): JsonResponse
    {
        return ApiResponse::success(data: new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategoryAction $updateCategory): JsonResponse
    {
        $category = $updateCategory->handle($category, $request->validated());

        return ApiResponse::success(data: new CategoryResource($category));
    }

    public function destroy(Category $category, DeleteCategoryAction $deleteCategory): JsonResponse
    {
        $deleteCategory->handle($category);

        return ApiResponse::success(message: 'تم حذف القسم');
    }
}
