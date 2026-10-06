<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchBusinessHoursResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchBusinessHoursController extends Controller
{
    public function __invoke(Branch $branch): JsonResponse
    {
        abort_unless($branch->is_active, 404);

        return ApiResponse::success(data: new BranchBusinessHoursResource($branch));
    }
}
