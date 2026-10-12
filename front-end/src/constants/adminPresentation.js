const roleLabels={super_admin:'المدير العام',manager:'مدير',cashier:'كاشير',kitchen:'المطبخ',customer:'عميل',driver:'مندوب توصيل'}
export const adminRoleLabel=value=>roleLabels[value]||value
const domainLabels={dashboard:'لوحة التحكم',orders:'الطلبات',payments:'المدفوعات',products:'المنتجات',categories:'التصنيفات',options:'خيارات المنتجات',branches:'الفروع',delivery_zones:'مناطق التوصيل',inventory:'المخزون',promotions:'العروض',coupons:'الكوبونات',customers:'العملاء',notifications:'الإشعارات',reports:'التقارير',settings:'الإعدادات',cms:'المحتوى',staff:'الموظفون',roles:'الأدوار',activity_logs:'سجل النشاط',driver:'وصول المندوب',drivers:'مناديب التوصيل'}
export const adminDomainLabel=value=>domainLabels[value]||value
