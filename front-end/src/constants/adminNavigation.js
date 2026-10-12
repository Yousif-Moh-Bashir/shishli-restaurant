// Actual Laravel route permissions, not a role-to-permission matrix.
import { operationPermissions } from './settings'
import { reportPermissions } from './reports'
export const canAccessNavigation = (auth, item) => item.permissionsAny ? item.permissionsAny.some(permission => auth.can(permission)) : auth.can(item.permission)
export const adminNavigation = [
  { path: '/admin', label: 'نظرة عامة', icon: 'layout-grid', permission: 'dashboard.view' },
  { path: '/admin/reports', label: 'التقارير', icon: 'chart-column', permissionsAny: reportPermissions },
  { path: '/admin/orders', label: 'الطلبات', icon: 'shopping-bag', permission: 'orders.view' },
  { path: '/admin/kitchen', label: 'المطبخ', icon: 'chef-hat', permission: 'orders.view' },
  { path: '/admin/payments', label: 'المدفوعات', icon: 'wallet', permission: 'payments.view' },
  { path: '/admin/drivers', label: 'مناديب التوصيل', icon: 'truck', permission: 'drivers.view' },
  { path: '/admin/notifications', label: 'الإشعارات', icon: 'bell', permission: 'notifications.view' },
  { path: '/admin/products', label: 'المنتجات', icon: 'utensils', permission: 'products.view' },
  { path: '/admin/branches', label: 'الفروع', icon: 'store', permission: 'branches.view' },
  { path: '/admin/product-options', label: 'خيارات المنتجات', icon: 'sliders-horizontal', permission: 'options.view' },
  { path: '/admin/categories', label: 'التصنيفات', icon: 'tags', permission: 'categories.view' },
  { path: '/admin/offers', label: 'العروض', icon: 'badge-percent', permission: 'promotions.view' },
  { path: '/admin/customers', label: 'العملاء', icon: 'user-round', permission: 'customers.view' },
  { path: '/admin/settings', label: 'الإعدادات', icon: 'settings', permissionsAny: ['settings.view','settings.audit.view',...operationPermissions] },
  { path: '/admin/delivery-zones', label: 'مناطق التوصيل', icon: 'map-pinned', permission: 'delivery_zones.view' },
  {path:'/admin/staff',label:'الموظفون',icon:'users',permission:'staff.view'},
  {path:'/admin/roles',label:'الأدوار والصلاحيات',icon:'shield-check',permission:'roles.view'},
  {path:'/admin/activity-logs',label:'سجل النشاط',icon:'history',permission:'activity_logs.view'},
  {path:'/admin/inventory',label:'المخزون',icon:'boxes',permissionsAny:['inventory.view','inventory.movements.view']},
  {path:'/admin/cms',label:'محتوى الموقع',icon:'panels-top-left',permissionsAny:['cms.pages.view','cms.banners.view','cms.gallery.view']},
]

// Group references keep the original routes and their permission contracts intact.
export const adminNavigationGroups = [
  { key:'overview', label:'الرئيسية', paths:['/admin','/admin/reports'] },
  { key:'operations', label:'العمليات', paths:['/admin/orders','/admin/kitchen','/admin/payments'] },
  { key:'catalog', label:'القائمة والمخزون', paths:['/admin/products','/admin/categories','/admin/product-options','/admin/inventory'] },
  { key:'branches', label:'الفروع والتوصيل', paths:['/admin/branches','/admin/delivery-zones','/admin/drivers'] },
  { key:'customers', label:'العملاء والتسويق', paths:['/admin/customers','/admin/offers','/admin/notifications'] },
  { key:'content', label:'المحتوى', paths:['/admin/cms'] },
  { key:'management', label:'الإدارة', paths:['/admin/staff','/admin/roles','/admin/activity-logs'] },
  { key:'system', label:'النظام', paths:['/admin/settings'] },
]

