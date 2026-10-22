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
                'delivery_exceptions.view', 'delivery_exceptions.fail', 'delivery_exceptions.return',
                'delivery_exceptions.release', 'delivery_exceptions.cancel',
                'delivery_exceptions.resolve_custody',
                'driver.access', 'drivers.view', 'drivers.manage', 'drivers.reassign', 'drivers.settle_cash',
                'staff.view', 'staff.create', 'staff.update', 'staff.activate', 'staff.deactivate',
                'roles.view', 'roles.update_permissions', 'activity_logs.view',
                'cms.pages.view', 'cms.pages.update',
                'cms.banners.view', 'cms.banners.create', 'cms.banners.update', 'cms.banners.delete',
                'cms.gallery.view', 'cms.gallery.create', 'cms.gallery.update', 'cms.gallery.delete',
                'inventory.view', 'inventory.create', 'inventory.receive', 'inventory.adjust', 'inventory.waste', 'inventory.movements.view',
                'settings.view', 'settings.update', 'settings.audit.view',
                'branches.hours.view', 'branches.hours.update',
                'branches.closures.view', 'branches.closures.create', 'branches.closures.update', 'branches.closures.delete',
                'dashboard.view', 'reports.orders.view', 'reports.sales.view', 'reports.branches.view',
                'reports.products.view', 'reports.payments.view', 'reports.promotions.view',
                'reports.customers.view', 'reports.delivery.view', 'reports.export',
                'customers.activate', 'customers.deactivate',
                'notifications.view', 'notifications.retry',
                'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
                'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete',
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
                'super_admin' => array_values(array_diff($permissions, ['delivery_exceptions.cancel'])),
                'manager' => array_values(array_diff($permissions, ['settings.manage', 'settings.update', 'roles.update_permissions', 'activity_logs.view', 'delivery_exceptions.resolve_custody', 'delivery_exceptions.cancel'])),
                'cashier' => ['dashboard.view', 'reports.orders.view', 'orders.view', 'orders.create', 'orders.confirm', 'orders.complete', 'products.view', 'customers.view', 'payments.view', 'payments.collect_cash', 'drivers.view', 'drivers.settle_cash'],
                'kitchen' => ['orders.view', 'orders.update_status', 'orders.start_preparing', 'orders.mark_ready'],
                'customer' => [],
                'driver' => ['driver.access'],
            ];

            foreach ($roles as $name => $rolePermissions) {
                $role = Role::findOrCreate($name, 'web');
                if (in_array($name, ['super_admin', 'manager'], true)
                    && $role->hasPermissionTo('delivery_exceptions.cancel')) {
                    $rolePermissions[] = 'delivery_exceptions.cancel';
                }

                if (in_array($name, ['super_admin', 'customer'], true) || ! $role->permissions_customized) {
                    $role->syncPermissions($rolePermissions);
                }
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
