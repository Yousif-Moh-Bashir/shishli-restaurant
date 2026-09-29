<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(function (): void {
            $permissions = [
                'branches.view',
                'branches.create',
                'branches.update',
                'branches.delete',
                'orders.view',
                'orders.create',
                'orders.update_status',
                'orders.cancel',
                'products.view',
                'products.create',
                'products.update',
                'products.delete',
                'categories.manage',
                'offers.manage',
                'customers.view',
                'reports.view',
                'settings.manage',
            ];

            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            $roles = [
                'super_admin' => $permissions,
                'manager' => array_values(array_diff($permissions, ['settings.manage'])),
                'cashier' => ['orders.view', 'orders.create', 'products.view', 'customers.view'],
                'kitchen' => ['orders.view', 'orders.update_status'],
                'customer' => [],
            ];

            foreach ($roles as $name => $rolePermissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($rolePermissions);
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
