<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(20);

        return ApiResponse::paginated(BranchResource::collection($branches));
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->validated());

        return ApiResponse::success(data: new BranchResource($branch->refresh()), message: 'تم إنشاء الفرع بنجاح.', statusCode: 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        return ApiResponse::success(data: new BranchResource($branch));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return ApiResponse::success(data: new BranchResource($branch->refresh()), message: 'تم تحديث الفرع بنجاح.');
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $branch->delete();

        return ApiResponse::success(message: 'تم حذف الفرع بنجاح.');
    }
}
