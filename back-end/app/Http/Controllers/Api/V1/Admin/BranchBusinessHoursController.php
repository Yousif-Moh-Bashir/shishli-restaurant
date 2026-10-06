<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Branches\ReplaceBusinessHoursAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReplaceBusinessHoursRequest;
use App\Http\Resources\Api\V1\BranchBusinessHoursResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchBusinessHoursController extends Controller
{
    public function index(Branch $branch): JsonResponse
    {
        return ApiResponse::success(data: new BranchBusinessHoursResource($branch));
    }

    public function update(ReplaceBusinessHoursRequest $request, Branch $branch, ReplaceBusinessHoursAction $action): JsonResponse
    {
        return ApiResponse::success(data: new BranchBusinessHoursResource($action->handle($branch, $request->validated('hours'))));
    }
}
