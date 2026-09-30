<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MenuRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Http\Resources\Api\V1\MenuCategoryResource;
use App\Http\Resources\Api\V1\MenuProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    public function index(MenuRequest $request, MenuService $menu): JsonResponse
    {
        $filters = $request->validated();
        $branch = $menu->branch($filters['branch']);

        return ApiResponse::success(data: [
            'branch' => new BranchResource($branch),
            'categories' => MenuCategoryResource::collection($menu->categories($branch, $filters)),
        ]);
    }

    public function show(MenuRequest $request, Product $product, MenuService $menu): JsonResponse
    {
        $branch = $menu->branch($request->validated('branch'));

        return ApiResponse::success(data: new MenuProductResource($menu->product($branch, $product)));
    }
}
