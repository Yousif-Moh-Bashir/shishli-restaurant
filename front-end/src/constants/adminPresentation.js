const roleLabels={super_admin:'المدير العام',manager:'مدير',cashier:'كاشير',kitchen:'المطبخ',customer:'عميل',driver:'مندوب توصيل'}
export const adminRoleLabel=value=>roleLabels[value]||value
const domainLabels={dashboard:'لوحة التحكم',orders:'الطلبات',payments:'المدفوعات',products:'المنتجات',categories:'التصنيفات',options:'خيارات المنتجات',branches:'الفروع',delivery_zones:'مناطق التوصيل',inventory:'المخزون',promotions:'العروض',coupons:'الكوبونات',customers:'العملاء',notifications:'الإشعارات',reports:'التقارير',settings:'الإعدادات',cms:'المحتوى',staff:'الموظفون',roles:'الأدوار',activity_logs:'سجل النشاط',driver:'وصول المندوب',drivers:'مناديب التوصيل'}
export const adminDomainLabel=value=>domainLabels[value]||value

const permissionSubjects={
  ...domainLabels,
  'cms.pages':'صفحات الموقع',
  'cms.banners':'اللافتات الإعلانية',
  'cms.gallery':'معرض الصور',
  'inventory.movements':'حركات المخزون',
  'settings.audit':'سجل تغييرات الإعدادات',
  'branches.hours':'ساعات عمل الفروع',
  'branches.closures':'الإغلاقات المؤقتة للفروع',
  'branches.products':'منتجات الفروع',
  'reports.orders':'تقارير الطلبات',
  'reports.sales':'تقارير المبيعات',
  'reports.branches':'تقارير الفروع',
  'reports.products':'تقارير المنتجات',
  'reports.payments':'تقارير المدفوعات',
  'reports.promotions':'تقارير العروض',
  'reports.customers':'تقارير العملاء',
  'reports.delivery':'تقارير التوصيل',
  offers:'العروض',
}
const permissionActions={view:'عرض',create:'إضافة',update:'تعديل',delete:'حذف',manage:'إدارة',activate:'تفعيل',deactivate:'إيقاف'}
const permissionLabels={
  'driver.access':'الدخول إلى بوابة مندوب التوصيل',
  'drivers.reassign':'إعادة تعيين مندوب توصيل للطلب',
  'drivers.settle_cash':'تسوية المبالغ النقدية مع مناديب التوصيل',
  'roles.update_permissions':'تعديل صلاحيات الأدوار',
  'inventory.receive':'استلام كميات في المخزون',
  'inventory.adjust':'تسوية كميات المخزون',
  'inventory.waste':'تسجيل الهدر في المخزون',
  'reports.export':'تصدير التقارير',
  'notifications.retry':'إعادة إرسال الإشعارات المتعثرة',
  'orders.update_status':'تغيير حالة الطلبات',
  'orders.cancel':'إلغاء الطلبات',
  'orders.confirm':'تأكيد الطلبات',
  'orders.start_preparing':'بدء تحضير الطلبات',
  'orders.mark_ready':'تحديد الطلبات كجاهزة',
  'orders.dispatch':'إرسال الطلبات للتوصيل',
  'orders.complete':'إكمال الطلبات',
  'payments.collect_cash':'تحصيل المدفوعات النقدية',
  'branches.closures.create':'إضافة إغلاق مؤقت للفرع',
}
export function adminPermissionLabel(value){
  if(permissionLabels[value])return permissionLabels[value]
  const parts=value.split('.')
  const action=permissionActions[parts.pop()]
  const subject=permissionSubjects[parts.join('.')]
  return action&&subject?`${action} ${subject}`:'صلاحية غير معروفة'
}
