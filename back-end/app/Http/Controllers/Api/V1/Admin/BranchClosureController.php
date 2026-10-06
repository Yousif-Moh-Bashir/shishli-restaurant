<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Branches\ManageBranchClosureAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBranchClosureRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchClosureRequest;
use App\Http\Resources\Api\V1\BranchClosureResource;
use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\BranchClosure;
use Illuminate\Http\JsonResponse;

class BranchClosureController extends Controller
{
    public function index(Branch $branch): JsonResponse
    {
        return ApiResponse::paginated(BranchClosureResource::collection($branch->closures()->orderByDesc('starts_at')->paginate(20)));
    }

    public function store(StoreBranchClosureRequest $request, Branch $branch, ManageBranchClosureAction $action): JsonResponse
    {
        return ApiResponse::success(data: new BranchClosureResource($action->save($branch, $request->validated(), $request->user())), statusCode: 201);
    }

    public function update(UpdateBranchClosureRequest $request, Branch $branch, BranchClosure $closure, ManageBranchClosureAction $action): JsonResponse
    {
        abort_unless($closure->branch_id === $branch->id, 404);

        return ApiResponse::success(data: new BranchClosureResource($action->save($branch, $request->validated(), $request->user(), $closure)));
    }

    public function destroy(Branch $branch, BranchClosure $closure, ManageBranchClosureAction $action): JsonResponse
    {
        abort_unless($closure->branch_id === $branch->id, 404);
        $action->delete($branch, $closure);

        return ApiResponse::success(message: 'تم حذف الإغلاق المؤقت.');
    }
}
