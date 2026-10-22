# فحص طلب التوصيل — 2026-10-21

## نطاق النتائج

لم يتوفر UUID الطلب أو اتصال إنتاج معتمد أثناء إعداد هذا الملف. لم تُقرأ بيانات
الإنتاج ولم تُنفذ استعلامات أو أوامر تشغيل عليه. الأدلة المسماة production في
docs/release/evidence تخص بناء إنتاج محليًا مع API معزول، وليست إثباتًا لحالة VPS.

المصدر المحلي HEAD: `08273772f395bb2aae97f176bb4923d90371ff8d`.
توجد تعديلات محلية سابقة في useNewOrderSound.js وDriverLayout.vue وCheckoutView.vue
وملف اختبار driver-order-sound.mjs؛ لذلك لا يكفي SHA للـ commit لإثبات تطابق الملفات.
لم تُعدّل هذه الملفات. لا توجد مقارنة مكتملة مع نسخة منشورة.

## الفحص المالي وقاعدة البيانات — أوامر للمراجعة فقط

الملف المصاحب DELIVERY_READ_ONLY_INSPECTION.sql يستخدم SELECT ومعاملة READ ONLY
تنتهي بـ ROLLBACK، ولا يشغّل Laravel أو Eloquent أو Webhook أو Jobs.
استخدم حساب SELECT-only موجودًا، والاتصال المعتمد مسبقًا بقاعدة الإنتاج؛ لا تنشئ
حسابًا ولا تعدّل الصلاحيات في هذه المرحلة. لا تنفّذ الملف على اتصال مفترض.

قبل التشغيل: استبدل جميع REPLACE_ORDER_UUID بمعرف الطلب الصحيح بصيغة UUID، وراجع
الملف. إذا لم يتوفر إلا رقم الطلب، استخدم الاستعلام المحدود التالي ثم انقل UUID
داخل بيئة الفحص الموثوقة، دون إظهار بيانات عميل:

```sql
SELECT uuid FROM orders WHERE order_number = 'REPLACE_ORDER_NUMBER' LIMIT 2;
```

تحقق من الجداول والمحرك والأعمدة دون قراءة صفوف أعمال:

```sql
SELECT table_name, engine FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name IN
('orders','users','branches','payments','payment_transactions',
 'payment_webhook_events','driver_cash_collections','driver_cash_settlements',
 'order_status_histories','order_delivery_histories','admin_activity_logs');

SELECT table_name, column_name, data_type FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN
('orders','users','branches','payments','payment_transactions',
 'payment_webhook_events','driver_cash_collections','driver_cash_settlements',
 'order_status_histories','order_delivery_histories','admin_activity_logs')
ORDER BY table_name, ordinal_position;
```

راجع الاختلافات قبل التنفيذ؛ يفترض snapshot جداول InnoDB. لا تتجاوز خطأ schema.
مثال من جهاز لديه MySQL client واتصال login-path معتمد قائم بالفعل:

```sh
mysql --login-path=shishli-prod-readonly --database=REPLACE_CONFIRMED_DATABASE --batch --raw < DELIVERY_READ_ONLY_INSPECTION.sql
```

هذا اسم اتصال توضيحي غير مكتشف. لا تستخدم --force أو كلمة مرور في سطر الأوامر.
عند خطأ أوقف الفحص وأغلق الجلسة. تأكد أن target_count=1 قبل اعتماد أي نتائج.
احتفظ بالمخرجات في قناة تشغيل مقيدة؛ المفاتيح الداخلية مستعارة وليست معلومات
مناسبة للنشر. الاستعلامات لا تعرض ملاحظات حرة أو payload أو مراجع مزود الدفع.
لا تنسخ logs عامة أو HAR أو Copy as cURL لاحتوائها المحتمل على أسرار وبيانات عميل.

أحداث Webhook غير المرتبطة بـ payment_id لا تظهر؛ غياب الأحداث لا يثبت أن المزود
لم يرسل حدثًا. أي مطابقة إضافية تتم بواسطة المشغل المخول داخل النظام المالي،
ويشارك فقط نتيجة المطابقة وكود خطأ منقح، دون المراجع أو payload.

## إثبات عقد API والواجهة دون نسخ بيانات شخصية

من جلسة المندوب الحالية المخولة، راقب طلب GET الموجود لتفاصيل الطلب:
`/api/v1/driver/orders/{uuid}`. لا تضغط قبولًا أو تحصيلًا أو تسليمًا كاختبار.
شارك يدويًا الحقول التالية فقط من data، دون كامل الاستجابة أو Headers:

```text
HTTP status
preparation_status, delivery_status, payment_method, payment_status
cash_due, available_actions
```

من GET قائمة طلبات المندوب، شارك فقط driver_context.can_accept وblocking_reason
وحالات active_order وis_previous_day، دون أرقام طلبات أخرى أو معرفاتها.
من GET /api/v1/driver/me شارك enabled وavailable وaccount_active فقط.
نجاح GET التفاصيل يثبت المرور بصلاحية الدور وdriver.access وملكية الطلب وفرعه
في النسخة المحلية؛ لا يثبت تطابق نسخة الإنتاج. عند 403/404 لا تستنتج حالات الدفع؛
يفحص المسؤول الفعلي الدور والصلاحية والفرع والإسناد داخل لوحة الإدارة.

`cash_due=0` لا يعني دفعًا إلكترونيًا ناجحًا: الكود يعيده صفرًا أيضًا لطلب network
معلق أو أي طريقة غير نقدية. ورسالة تعطيل التسليم العامة لا تحدد سبب المنع وحدها.

## مقارنة الإصدار الفعلي — أوامر قراءة للمراجعة

شغّل في checkout الإصدار الفعلي، إن كان Git موجودًا:

```sh
git rev-parse HEAD
git status --short
```

لا تغيّر safe.directory عالميًا. عند اختلاف مالك موثوق محليًا يمكن تمرير
`git -c safe.directory=ABSOLUTE_TRUSTED_REPO rev-parse HEAD` لهذه العملية فقط.
لا تطبع git remote أو إعدادات البيئة، فقد تحتوي بيانات اعتماد.

في Docker، حدد أولًا أسماء خدمات الإصدار من مخزون النشر. الأوامر التالية تفترض
app وnginx وفق compose المحلي فقط؛ لا تفترض أنها خدمة الإنتاج نفسها:

```sh
docker compose ps --format json
docker compose images
docker compose exec -T app sha256sum app/Services/Drivers/DriverOrderService.php app/Actions/Drivers/TransitionDeliveryAction.php app/Services/OrderStateMachine.php app/Services/OrderOperationAuthorization.php app/Actions/Payments/CollectCashPaymentAction.php app/Services/Payments/PaymentStatusWriter.php
docker compose exec -T nginx sha256sum /usr/share/nginx/html/index.html
docker compose exec -T nginx sh -c 'cd /usr/share/nginx/html && find assets -type f -exec sha256sum {} +'
```

لا تشارك مخرجات docker inspect الكاملة أو compose config أو env.
قارن SHA256 بملفات checkout المحلي وfront-end/dist/assets وindex.html، دون إعادة
البناء. dist الموجود قد يكون أقدم من المصدر، لذا التطابق معه يثبت تطابق الأثر
فقط. عدم التطابق لا يثبت وحده اختلاف المنطق؛ قد تختلف إعدادات البناء أو نسخة الأدوات.
قارن أيضًا composer.lock وpackage-lock.json عند الحاجة دون تثبيت تبعيات.

من Network في المتصفح سجل أسماء وبصمات JS المحمّلة فعليًا، خصوصًا DriverOrderView
وdriverDelivery، وقارنها بما يخدمه Nginx؛ افحص هل استجابة index.html جاءت من cache.
لا تمسح cache أو تعيد تشغيل خدمة أثناء جمع خط الأساس. PHP OPcache في إعداد Docker
المحلي لا يفحص تبدل الملفات؛ تساوي ملفات القرص وحده لا يثبت نسخة التنفيذ في الذاكرة.
راجع أوقات إنشاء الحاويات وسجل النشر وبدء العمليات، دون restart في هذه المرحلة.

بصمات محلية SHA256 وقت الفحص:

| الملف | SHA256 |
|---|---|
| DriverOrderService.php | AE6709C9EE0DBAAFD14280847F343DC356FFE87AF0C90D946F4F7F26BAA8C0C9 |
| TransitionDeliveryAction.php | FEAB034D56523E22976A4C7232304138AF449661785D2F3EB89671B889170C29 |
| OrderStateMachine.php | 64A8550D3F9DDD2C1DD918DDA178E989738AFF7CEB2CA29F046492198D1D8684 |
| OrderOperationAuthorization.php | D0FACAB8864AF74A770244D4F2F4A49ACC077186563E004757A03747861C14CC |
| CollectCashPaymentAction.php | 5E6AB5ABD1E05ED1AE043A3BEAE3F431D4FCE3696DA2267ACB2B3A014BA5E9F2 |
| PaymentStatusWriter.php | 5AE44C5325CCD508F1398E96D2B049340B1C163E19A74CDCA6828EFEDBD3B753 |
| src/views/driver/DriverOrderView.vue | 899B8868ABB1B2A214C4EA4B1DF69DBDDF3355053DD71826A6AF3A39F43F32FB |
| src/constants/driverDelivery.js | AB80856F56FD2F8AA9BBE152231EC5927A4E442290230783FFD4F29438029018 |
| dist/index.html | 4FEC7E89A015CD633A06473BF3645C05954EAB7F14B83DDD4AE81E036276A1F3 |

## تفسير النتائج والحل المشروط

| الدليل | التصنيف | المعالجة المقترحة لاحقًا، لا تنفذ أثناء الفحص |
|---|---|---|
| on_the_way + out_for_delivery + pending | قيد دفع فعلي في الكود؛ ليس دليل دفع | نقد: التحصيل المعتمد بعد قبض فعلي فقط؛ شبكة: تأكيد صاحب صلاحية بعد تحقق مالي؛ بطاقة: تحقق المزود أولًا |
| payment paid مطابق + order payment_status pending | عدم اتساق يحتاج تحقق أصل المعاملة | إجراء مصالحة مالي مدقق ومقفل ومحدد للطلب، بعد اعتماد منفصل؛ لا تعديل SQL ولا إعادة التحصيل |
| on_the_way + status ليس out_for_delivery | حالات تشغيل غير متسقة | إثبات التاريخ والعهدة ثم إجراء تصحيح مدقق بعد اعتماد؛ لا فرض التسليم |
| out_for_delivery + on_the_way + paid وAPI يعيد delivered | بوابة الخادم تسمح؛ واجهة/أثر منشور محتمل | مطابقة JS الفعلي واستجابة الطلب والتوقيت قبل أي تغيير |
| الشروط نفسها لكن API لا يعيد delivered | Backend أو ملكية/نسخة/توقيت غير مطابق | مقارنة مصدر التنفيذ والفرع والإسناد وHTTP؛ لا تجاوز API |
| failed على طلب غير نهائي | نقص مسار استثناء مؤكد في المنطق المحلي | إعادة إسناد إذا ستتم محاولة حقيقية، وإلا معالجة إرجاع/إنهاء عهدة جديدة بعد التنفيذ والاعتماد |
| delivered والقبول لا يزال ممنوعًا | ليس انتظار الكاشير وحده بحسب الكود المحلي | فحص بقية المهام النشطة وتعطيل الحساب/التوفر/الفرع والإصدار |

يمكن استخدام مسار التسليم ثم الإكمال الحاليين فقط بعد إثبات الدفع والتسليم الفعليين.
لا تُغيّر الحالة لمجرد تحرير المندوب. إعادة الإسناد تنقل المهمة ولا تثبت عودة الطلب
ولا تنقل النقد الذي حصله المندوب السابق. لا يُسترد مبلغ تلقائيًا ولا تُعاد مخزونات
أطعمة بمجرد تسجيل العودة. إن كان الطلب خرج للتوصيل ولن يُسلّم، لا يوجد حاليًا مسار
عام آمن لإغلاقه؛ يظل التنفيذ مؤجلًا إلى Action استثناء مدقق وفق التصميم المعتمد.

## خطة التنفيذ التالية بعد الإثبات والاعتماد

1. تثبيت السبب الفعلي بأدلة SQL وAPI والإصدار في اختبار معزول.
2. تصحيح العيب المثبت أو إضافة مصالحة محددة ومدققة عند وجود عدم اتساق.
3. إضافة استثناءات التوصيل والعودة وحسم العهدة والصلاحيات والتدقيق ضمن Transactions.
4. توحيد available_actions وأسباب التعطيل وتحديث واجهات الأطراف.
5. اختبار النقد والشبكة والبطاقة والقديم والفشل والإلغاء وإعادة الإسناد والتكرار
   والتزامن وحماية المكتمل والمدفوع؛ لا اختبارات تكتب على الإنتاج.
6. إعداد معالجة الطلب الحالي للمراجعة، ثم نشر منظم في مرحلة مستقلة مصرح بها.

لم تُنفّذ اختبارات أو Migrations أو Commit أو Push أو نشر. تمت إضافة وثيقتي الفحص
فقط؛ لا تغيير لكود التطبيق أو بياناته.
