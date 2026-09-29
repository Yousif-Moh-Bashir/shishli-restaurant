<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return ApiResponse::success(data: BranchResource::collection($branches));
    }

    public function show(Branch $branch): JsonResponse
    {
        abort_unless($branch->is_active, 404);

        return ApiResponse::success(data: new BranchResource($branch));
    }
}
