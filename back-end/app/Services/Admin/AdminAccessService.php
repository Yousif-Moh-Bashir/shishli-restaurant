<?php

namespace App\Services\Admin;

use App\Models\User;

class AdminAccessService
{
    public const STAFF_ROLES = ['super_admin', 'manager', 'cashier', 'kitchen'];

    public function allowed(?User $user): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }
        $staff = $user->hasAnyRole(self::STAFF_ROLES);

        return $staff || (! $user->hasRole('customer') && $user->getAllPermissions()
            ->contains(fn ($permission): bool => ! str_starts_with($permission->name, 'driver.')));
    }

    public function assertAllowed(?User $user): void
    {
        abort_unless($this->allowed($user), 403);
    }
}
