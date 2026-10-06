<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StaffService
{
    public function __construct(private AdminActivityService $audit) {}

    public function query(): Builder
    {
        return User::whereHas('roles', fn (Builder $roles): Builder => $roles->where('guard_name', 'web')->whereIn('name', AdminAccessService::STAFF_ROLES));
    }

    public function find(string $uuid): User
    {
        return $this->query()->with('roles.permissions')->where('uuid', $uuid)->firstOrFail();
    }

    private function lockSystemRole(): void
    {
        Role::where('name', 'super_admin')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        if ($actor->hasRole('super_admin')) {
            return;
        }
        abort_if($target->hasRole('super_admin'), 403);
        foreach ($target->getAllPermissions() as $permission) {
            abort_unless($actor->can($permission->name), 403);
        }
    }

    private function authorizeRoles(User $actor, array $roles): void
    {
        foreach ($roles as $name) {
            $role = Role::findByName($name, 'web');
            abort_unless(in_array($name, AdminAccessService::STAFF_ROLES, true), 403);
            if ($name === 'super_admin') {
                abort_unless($actor->hasRole('super_admin'), 403);
            }
            if (! $actor->hasRole('super_admin')) {
                foreach ($role->permissions as $permission) {
                    abort_unless($actor->can($permission->name), 403);
                }
            }
        }
    }

    public function create(User $actor, #[\SensitiveParameter] array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $this->lockSystemRole();
            $this->authorizeRoles($actor, $data['roles']);
            $user = User::create(Arr::only($data, ['name', 'email', 'phone', 'password']));
            $user->syncRoles($data['roles']);
            $this->audit->record($actor, 'staff.create', 'user', $user->uuid, [], $this->audit->values($user));

            return $user->load('roles.permissions');
        });
    }

    public function update(User $actor, User $user, #[\SensitiveParameter] array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            $this->lockSystemRole();
            $user = $this->query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($actor, $user);
            $old = $this->audit->values($user);
            if (isset($data['roles'])) {
                $this->authorizeRoles($actor, $data['roles']);
                if ($user->hasRole('super_admin') && ! in_array('super_admin', $data['roles'], true) && $user->is_active) {
                    $this->assertAnotherSuperAdmin($user);
                }
                $user->syncRoles($data['roles']);
                $user->unsetRelation('roles');
                $user->tokens()->delete();
            }
            $user->fill(Arr::only($data, ['name', 'email', 'phone', 'password']))->save();
            if (isset($data['password'])) {
                $user->tokens()->delete();
            }
            $new = $this->audit->values($user);
            if (isset($data['password'])) {
                $new['password_changed'] = true;
            }
            $this->audit->record($actor, 'staff.update', 'user', $user->uuid, $old, $new);

            return $user->load('roles.permissions');
        });
    }

    private function assertAnotherSuperAdmin(User $user): void
    {
        $others = User::where('is_active', true)->whereKeyNot($user->id)->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', 'super_admin')->where('guard_name', 'web'))->exists();
        if (! $others) {
            throw ValidationException::withMessages(['roles' => 'LAST_SUPER_ADMIN']);
        }
    }

    public function setActive(User $actor, User $user, bool $active): User
    {
        return DB::transaction(function () use ($actor, $user, $active): User {
            $this->lockSystemRole();
            $user = $this->query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($actor, $user);
            if (! $active) {
                if ($actor->id === $user->id) {
                    throw ValidationException::withMessages(['is_active' => 'SELF_DEACTIVATION_FORBIDDEN']);
                }
                if ($user->is_active && $user->hasRole('super_admin')) {
                    $this->assertAnotherSuperAdmin($user);
                }
            }
            $old = $this->audit->values($user);
            $user->is_active = $active;
            $user->save();
            if (! $active) {
                $user->tokens()->delete();
            }
            $this->audit->record($actor, $active ? 'staff.activate' : 'staff.deactivate', 'user', $user->uuid, $old, $this->audit->values($user));

            return $user->load('roles.permissions');
        });
    }
}
