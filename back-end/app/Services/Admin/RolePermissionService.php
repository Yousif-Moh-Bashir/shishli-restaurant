<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionService
{
    public function __construct(private AdminActivityService $audit) {}

    public function roles(): array
    {
        return Role::where('guard_name', 'web')->whereIn('name', [...AdminAccessService::STAFF_ROLES, 'customer', 'driver'])->with('permissions')->orderBy('name')->get()->map(fn (Role $role): array => ['id' => $role->name, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()])->all();
    }

    public function groups(): array
    {
        return Permission::where('guard_name', 'web')->orderBy('name')->pluck('name')->groupBy(fn (string $name): string => explode('.', $name)[0])->map(fn ($items): array => $items->values()->all())->all();
    }

    public function update(User $actor, Role $role, array $permissions): array
    {
        abort_unless($actor->hasRole('super_admin') && $actor->can('roles.update_permissions'), 403);
        if (in_array($role->name, ['super_admin', 'driver'], true) || ($role->name === 'customer' && $permissions !== [])) {
            throw ValidationException::withMessages(['permissions' => 'PROTECTED_SYSTEM_ROLE']);
        }
        DB::transaction(function () use ($actor, $role, $permissions): void {
            Role::where('name', 'super_admin')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $old = $role->permissions->pluck('name')->sort()->values()->all();
            $role->syncPermissions($permissions);
            $role->forceFill(['permissions_customized' => true])->save();
            $this->audit->record($actor, 'roles.update_permissions', 'role', null, ['name' => $role->name, 'permissions' => $old], ['name' => $role->name, 'permissions' => $permissions]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return collect($this->roles())->firstWhere('name', $role->name);
    }
}
