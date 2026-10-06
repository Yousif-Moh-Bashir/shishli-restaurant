<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchAvailabilityResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Services\BranchAvailabilityService;
use Illuminate\Http\JsonResponse;

class BranchStatusController extends Controller
{
    public function __invoke(Branch $branch, BranchAvailabilityService $availability): JsonResponse
    {
        abort_unless($branch->is_active, 404);

        return ApiResponse::success(data: new BranchAvailabilityResource($availability->evaluate($branch)))->header('Cache-Control', 'private, no-store');
    }
}
