<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Categories\CreateCategoryAction;
use App\Actions\Categories\DeleteCategoryAction;
use App\Actions\Categories\UpdateCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with('parent')->withCount('products')
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(20);

        return ApiResponse::paginated(CategoryResource::collection($categories));
    }

    public function store(StoreCategoryRequest $request, CreateCategoryAction $createCategory): JsonResponse
    {
        $category = $createCategory->handle($request->validated());

        return ApiResponse::success(data: new CategoryResource($category), statusCode: 201);
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'children' => fn (HasMany $query): HasMany => $query->orderBy('sort_order')->orderBy('name')->orderBy('id')]);

        return ApiResponse::success(data: new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategoryAction $updateCategory): JsonResponse
    {
        return ApiResponse::success(data: new CategoryResource($updateCategory->handle($category, $request->validated())));
    }

    public function destroy(Category $category, DeleteCategoryAction $deleteCategory): JsonResponse
    {
        $deleteCategory->handle($category);

        return ApiResponse::success(message: 'تم حذف القسم');
    }
}
