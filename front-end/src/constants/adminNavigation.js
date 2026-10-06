// Actual Laravel route permissions, not a role-to-permission matrix.
import { operationPermissions } from './settings'
import { reportPermissions } from './reports'
export const canAccessNavigation = (auth, item) => item.permissionsAny ? item.permissionsAny.some(permission => auth.can(permission)) : auth.can(item.permission)
export const adminNavigation = [
  { path: '/admin', label: 'نظرة عامة', icon: 'badge-check', permission: 'dashboard.view' },
  { path: '/admin/reports', label: 'التقارير', icon: 'layout-grid', permissionsAny: reportPermissions },
  { path: '/admin/orders', label: 'الطلبات', icon: 'shopping-bag', permission: 'orders.view' },
  { path: '/admin/kitchen', label: 'المطبخ', icon: 'utensils', permission: 'orders.view' },
  { path: '/admin/payments', label: 'المدفوعات', icon: 'shopping-bag', permission: 'payments.view' },
  { path: '/admin/notifications', label: 'الإشعارات', icon: 'badge-check', permission: 'notifications.view' },
  { path: '/admin/products', label: 'المنتجات', icon: 'utensils', permission: 'products.view' },
  { path: '/admin/branches', label: 'الفروع', icon: 'layout-grid', permission: 'branches.view' },
  { path: '/admin/product-options', label: 'خيارات المنتجات', icon: 'settings', permission: 'options.view' },
  { path: '/admin/categories', label: 'التصنيفات', icon: 'layout-grid', permission: 'categories.view' },
  { path: '/admin/offers', label: 'العروض', icon: 'flame', permission: 'promotions.view' },
  { path: '/admin/customers', label: 'العملاء', icon: 'user-round', permission: 'customers.view' },
  { path: '/admin/settings', label: 'الإعدادات', icon: 'settings', permissionsAny: ['settings.view','settings.audit.view',...operationPermissions] },
  { path: '/admin/delivery-zones', label: 'مناطق التوصيل', icon: 'layout-grid', permission: 'delivery_zones.view' },
  {path:'/admin/staff',label:'الموظفون',icon:'user-round',permission:'staff.view'},
  {path:'/admin/roles',label:'الأدوار والصلاحيات',icon:'settings',permission:'roles.view'},
  {path:'/admin/activity-logs',label:'سجل النشاط',icon:'clock',permission:'activity_logs.view'},
  {path:'/admin/inventory',label:'المخزون',icon:'shopping-bag',permissionsAny:['inventory.view','inventory.movements.view']},
  {path:'/admin/cms',label:'محتوى الموقع',icon:'layout-grid',permissionsAny:['cms.pages.view','cms.banners.view','cms.gallery.view']},
]

// Group references keep the original routes and their permission contracts intact.
export const adminNavigationGroups = [
  { key:'overview', label:'الرئيسية', paths:['/admin','/admin/reports'] },
  { key:'operations', label:'العمليات', paths:['/admin/orders','/admin/kitchen','/admin/payments'] },
  { key:'catalog', label:'القائمة والمخزون', paths:['/admin/products','/admin/categories','/admin/product-options','/admin/inventory'] },
  { key:'branches', label:'الفروع والتوصيل', paths:['/admin/branches','/admin/delivery-zones'] },
  { key:'customers', label:'العملاء والتسويق', paths:['/admin/customers','/admin/offers','/admin/notifications'] },
  { key:'content', label:'المحتوى', paths:['/admin/cms'] },
  { key:'management', label:'الإدارة', paths:['/admin/staff','/admin/roles','/admin/activity-logs'] },
  { key:'system', label:'النظام', paths:['/admin/settings'] },
]

