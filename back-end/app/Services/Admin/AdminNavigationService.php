<?php

namespace App\Services\Admin;

use App\Models\User;

class AdminNavigationService
{
    public function get(User $user): array
    {
        $items = [
            ['dashboard', 'لوحة التحكم', '/dashboard', 'dashboard.view'],
            ['orders', 'الطلبات', '/orders', 'orders.view'], ['kitchen', 'المطبخ', '/kitchen', 'orders.start_preparing'],
            ['products', 'المنتجات', '/products', 'products.view'], ['categories', 'الأقسام', '/categories', 'categories.view'],
            ['options', 'خيارات المنتجات', '/options', 'options.view'], ['branches', 'الفروع', '/branches', 'branches.view'],
            ['inventory', 'المخزون', '/inventory', 'inventory.view'], ['customers', 'العملاء', '/customers', 'customers.view'],
            ['payments', 'المدفوعات', '/payments', 'payments.view'], ['promotions', 'العروض', '/promotions', 'promotions.view'],
            ['coupons', 'الكوبونات', '/coupons', 'coupons.view'], ['notifications', 'الإشعارات', '/notifications', 'notifications.view'],
            ['pages', 'الصفحات', '/pages', 'cms.pages.view'], ['banners', 'البنرات', '/banners', 'cms.banners.view'],
            ['gallery', 'المعرض', '/gallery', 'cms.gallery.view'], ['settings', 'الإعدادات', '/settings', 'settings.view'],
            ['business_hours', 'ساعات العمل', '/business-hours', 'branches.hours.view'],
            ['staff', 'الموظفون', '/staff', 'staff.view'], ['roles', 'الأدوار', '/roles', 'roles.view'],
            ['activity_logs', 'سجل النشاط', '/activity-logs', 'activity_logs.view'],
        ];
        $allowed = $user->getAllPermissions()->pluck('name')->all();
        $result = [];
        foreach ($items as [$key,$label,$route,$permission]) {
            if (in_array($permission, $allowed, true)) {
                $result[] = ['key' => $key, 'label' => $label, 'route' => $route, 'permission' => $permission];
            }
        }
        if (collect($allowed)->contains(fn (string $permission): bool => str_starts_with($permission, 'reports.') && str_ends_with($permission, '.view'))) {
            $result[] = ['key' => 'reports', 'label' => 'التقارير', 'route' => '/reports'];
        }

        return $result;
    }
}
