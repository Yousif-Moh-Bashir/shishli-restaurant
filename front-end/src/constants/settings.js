export const settingsGroups={general:'بيانات المطعم',contact:'التواصل',branding:'الهوية',orders:'استقبال الطلبات',pickup:'الاستلام',delivery:'التوصيل'}
export const settingsGroupDescriptions={
 general:'حدّث بيانات المطعم وموقعه والعملة والمنطقة الزمنية.',
 contact:'حدّث وسائل التواصل التي يستخدمها العملاء للوصول إلى المطعم.',
 branding:'اضبط الشعار ولون الهوية بما يناسب المطعم.',
 orders:'تحكّم باستقبال الطلبات وحدودها وخيارات الزوار والجدولة والصيانة.',
 pickup:'اضبط خدمة الاستلام والحد الأدنى للطلب ومدة التجهيز.',
 delivery:'اضبط خدمة التوصيل ومدة التجهيز ومتطلبات العنوان.',
}
// Presentation metadata for the 26 keys in RestaurantSettingDefinition.
export const settingFields={
 'restaurant.name':{label:'اسم المطعم',max:150,required:true},
 'restaurant.name_en':{label:'اسم المطعم بالإنجليزية',max:150,nullable:true},
 'restaurant.phone':{label:'هاتف المطعم',type:'tel',max:20,nullable:true,placeholder:'+966 11 000 0000',autocomplete:'tel',note:'رقم الهاتف المخصص لاستفسارات العملاء.'},
 'restaurant.whatsapp':{label:'واتساب',type:'tel',max:20,nullable:true,placeholder:'+966 50 000 0000',autocomplete:'tel',note:'رقم واتساب مع رمز الدولة لتسهيل التواصل.'},
 'restaurant.email':{label:'البريد الإلكتروني',type:'email',max:255,nullable:true,placeholder:'hello@example.com',autocomplete:'email',note:'البريد المخصص لاستقبال رسائل العملاء.'},
 'restaurant.currency':{label:'العملة',options:['SAR'],required:true},
 'restaurant.timezone':{label:'المنطقة الزمنية للمطعم',required:true,note:'تستخدم لساعات العمل والجدولة وتواريخ التقارير؛ مثال Asia/Riyadh.'},
 'restaurant.country':{label:'رمز الدولة (حرفان كبيران)',max:2,nullable:true},
 'restaurant.city':{label:'المدينة',max:150,nullable:true},
 'branding.logo_path':{label:'مسار الشعار',max:500,nullable:true,note:'مسار موجود لدى الخادم؛ رفع الوسائط خارج نطاق هذه المرحلة.'},
 'branding.primary_color':{label:'لون الهوية (#RRGGBB)',max:7,nullable:true},
 'orders.enabled':{label:'استقبال الطلبات في المطعم',sensitive:true},
 'orders.minimum_amount':{label:'الحد الأدنى العام للطلب',type:'number',min:0,maxValue:99999999.99,step:'0.01',required:true},
 'orders.maximum_items_per_order':{label:'الحد الأقصى لعدد القطع',type:'number',min:1,maxValue:10000,required:true},
 'orders.allow_guest_checkout':{label:'السماح بطلبات الزوار'},
 'orders.guest_tracking_token_days':{label:'مدة صلاحية تتبع الزائر بالأيام',type:'number',min:1,maxValue:365,required:true},
 'orders.allow_scheduled_orders':{label:'السماح بالطلبات المجدولة'},
 'orders.max_scheduling_days':{label:'أقصى مدة للجدولة بالأيام',type:'number',min:1,maxValue:366,required:true},
 'orders.maintenance_mode':{label:'صيانة استقبال الطلبات',sensitive:true,note:'تبقى القائمة والطلبات الحالية متاحة؛ يتوقف إنشاء الطلبات الجديدة.'},
 'orders.maintenance_message':{label:'رسالة صيانة الطلبات',max:500,required:true,note:'تظهر للعامة أثناء تفعيل صيانة الطلبات فقط.'},
 'pickup.enabled':{label:'تفعيل الاستلام من الفروع',sensitive:true},
 'pickup.minimum_amount':{label:'الحد الأدنى للاستلام',type:'number',min:0,maxValue:99999999.99,step:'0.01',required:true},
 'pickup.default_preparation_minutes':{label:'مدة تجهيز الاستلام بالدقائق',type:'number',min:1,maxValue:240,required:true},
 'delivery.enabled':{label:'تفعيل التوصيل',sensitive:true},
 'delivery.default_preparation_minutes':{label:'مدة تجهيز التوصيل بالدقائق',type:'number',min:1,maxValue:240,required:true},
 'delivery.require_coordinates':{label:'اشتراط إحداثيات عنوان التوصيل'},
}
export const operationPermissions=['branches.hours.view','branches.closures.view','branches.view']
export const weekdayLabels={1:'الاثنين',2:'الثلاثاء',3:'الأربعاء',4:'الخميس',5:'الجمعة',6:'السبت',7:'الأحد'}
