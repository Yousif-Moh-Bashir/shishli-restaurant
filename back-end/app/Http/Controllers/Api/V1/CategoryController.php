<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::active()->whereNull('parent_id')
            ->with(['children' => fn (HasMany $query): HasMany => $query->active()->orderBy('sort_order')->orderBy('name')->orderBy('id')])
            ->withCount(['products' => fn (Builder $query): Builder => $query->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return ApiResponse::success(data: CategoryResource::collection($categories));
    }

    public function show(Category $category): JsonResponse
    {
        abort_unless($category->is_active, 404);
        $category->load([
            'parent' => fn (BelongsTo $query): BelongsTo => $query->active(),
            'children' => fn (HasMany $query): HasMany => $query->active()->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        ]);

        return ApiResponse::success(data: new CategoryResource($category));
    }
}
