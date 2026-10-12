<?php

namespace App\Services\Drivers;

use App\Models\Branch;
use App\Models\User;
use App\Services\Admin\AdminActivityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DriverManagementService
{
    public function __construct(private DriverOrderService $orders, private AdminActivityService $audit) {}

    public function save(User $actor, ?User $driver, array $data): User
    {
        abort_unless($actor->can('drivers.manage'), 403);

        return DB::transaction(function () use ($actor, $driver, $data): User {
            $creating = $driver === null;
            if ($driver !== null) {
                $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
                abort_unless($driver->hasRole('driver'), 404);
                abort_if($actor->id === $driver->id, 403);
                if (! $creating && $driver->hasAnyRole(['super_admin', 'manager', 'cashier', 'kitchen'])) {
                    abort_unless($actor->hasRole('super_admin'), 403);
                }
                if (! $actor->hasRole('super_admin')) {
                    foreach ($driver->getAllPermissions() as $permission) {
                        abort_unless($permission->name === 'driver.access' || $actor->can($permission->name), 403);
                    }
                }
            }
            $branch = Branch::where('uuid', $data['branch_uuid'])->firstOrFail();
            $old = $driver ? ['driver_enabled' => $driver->driver_enabled, 'driver_available' => $driver->driver_available,
                'branch_uuid' => $driver->driverBranch?->uuid] : [];
            if ($driver && $driver->driver_branch_id !== $branch->id && $this->orders->active($driver)->exists()) {
                abort(409);
            }
            if ($driver === null) {
                $driver = User::create(Arr::only($data, ['name', 'email', 'phone', 'password']));
                $driver->assignRole('driver');
            } else {
                $driver->fill(Arr::only($data, ['name', 'email', 'phone', 'password']));
            }
            $driver->driver_branch_id = $branch->id;
            $driver->driver_enabled = $data['enabled'];
            $driver->driver_available = $data['available'] && $data['enabled'];
            $driver->save();
            if (isset($data['password'])) {
                $driver->tokens()->delete();
            }
            $this->audit->record($actor, $creating ? 'drivers.create' : 'drivers.update', 'user', $driver->uuid, $old,
                ['name' => $driver->name, 'branch_uuid' => $branch->uuid, 'driver_enabled' => $driver->driver_enabled,
                    'driver_available' => $driver->driver_available, 'password_changed' => isset($data['password'])]);

            return $driver;
        }, 3);
    }

    public function availability(User $driver, bool $available): User
    {
        return DB::transaction(function () use ($driver, $available): User {
            $driver = User::whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $this->orders->authorize($driver);
            abort_unless(! $available || $driver->driver_enabled, 403);
            $old = ['driver_available' => $driver->driver_available];
            $driver->driver_available = $available;
            $driver->save();
            $this->audit->record($driver, 'drivers.availability', 'user', $driver->uuid, $old, ['driver_available' => $available]);

            return $driver;
        }, 3);
    }
}
