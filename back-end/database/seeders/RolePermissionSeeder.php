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
                'delivery_zones.view',
                'delivery_zones.create',
                'delivery_zones.update',
                'delivery_zones.delete',
                'branches.view',
                'branches.create',
                'branches.update',
                'branches.delete',
                'branches.products.manage',
                'orders.view',
                'orders.create',
                'orders.update_status',
                'orders.cancel',
                'orders.confirm',
                'orders.start_preparing',
                'orders.mark_ready',
                'orders.dispatch',
                'orders.complete',
                'payments.view',
                'payments.collect_cash',
                'products.view',
                'products.create',
                'products.update',
                'products.delete',
                'options.view',
                'options.create',
                'options.update',
                'options.delete',
                'categories.manage',
                'categories.view',
                'categories.create',
                'categories.update',
                'categories.delete',
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
                'cashier' => ['orders.view', 'orders.create', 'orders.confirm', 'orders.complete', 'products.view', 'customers.view', 'payments.view', 'payments.collect_cash'],
                'kitchen' => ['orders.view', 'orders.update_status', 'orders.start_preparing', 'orders.mark_ready'],
                'customer' => [],
            ];

            foreach ($roles as $name => $rolePermissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($rolePermissions);
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
