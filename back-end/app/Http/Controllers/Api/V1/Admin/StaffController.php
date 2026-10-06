<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminListRequest;
use App\Http\Requests\Api\V1\Admin\StaffRequest;
use App\Http\Resources\Api\V1\AdminProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Admin\AdminListQuery;
use App\Services\Admin\StaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(AdminListRequest $request, StaffService $staff, AdminListQuery $lists): JsonResponse
    {
        $query = $staff->query()->with(['roles.permissions', 'permissions']);
        if ($request->filled('role')) {
            $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', $request->validated('role')));
        }

        return ApiResponse::paginated(AdminProfileResource::collection($lists->apply($query, $request->validated(), ['name', 'phone', 'email'], ['name', 'created_at', 'updated_at', 'is_active'], 'created_at', 'desc')->paginate($request->integer('per_page', 20))->withQueryString()));
    }

    public function show(User $staffUser): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($staffUser->load(['roles.permissions', 'permissions'])));
    }

    public function store(StaffRequest $request, StaffService $staff): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($staff->create($request->user(), $request->validated())), statusCode: 201);
    }

    public function update(StaffRequest $request, User $staffUser, StaffService $staff): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($staff->update($request->user(), $staffUser, $request->validated())));
    }

    public function activate(Request $request, User $staffUser, StaffService $staff): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($staff->setActive($request->user(), $staffUser, true)));
    }

    public function deactivate(Request $request, User $staffUser, StaffService $staff): JsonResponse
    {
        return ApiResponse::success(new AdminProfileResource($staff->setActive($request->user(), $staffUser, false)));
    }
}
