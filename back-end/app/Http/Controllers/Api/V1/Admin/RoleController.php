<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RolePermissionsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\RolePermissionService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(RolePermissionService $roles): JsonResponse
    {
        return ApiResponse::success($roles->roles());
    }

    public function permissions(RolePermissionService $roles): JsonResponse
    {
        return ApiResponse::success($roles->groups());
    }

    public function update(RolePermissionsRequest $request, string $role, RolePermissionService $roles): JsonResponse
    {
        abort_unless(in_array($role, [...AdminAccessService::STAFF_ROLES, 'customer', 'driver'], true), 404);
        $model = Role::where('name', $role)->where('guard_name', 'web')->firstOrFail();

        return ApiResponse::success($roles->update($request->user(), $model, $request->validated('permissions')));
    }
}
