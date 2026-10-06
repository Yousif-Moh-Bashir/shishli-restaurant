# ربط تتبع الطلب بالواجهة

- عند أول Guest Checkout، احفظ `data.tracking.order_number` و`data.tracking.token` ووقت `expires_at`. الحقل القديم `access_token` يحمل الرمز نفسه للتوافق. تكرار Checkout يعيد الطلب دون إعادة الرمز.
- صفحة `track-order.html` ترسل `POST /api/v1/orders/track` مع JSON:
  `{"order_number":"SH-...","token":"رمز Checkout"}`.
- العميل المسجل يستخدم `GET /api/v1/orders/{order_uuid}/tracking` مع مصادقة Sanctum. الملكية مطلوبة، حتى للمشرف عبر هذا المسار.
- جميع البيانات داخل `data`: `order`، `progress`، `timeline`، `estimates`، `branch`، `payment`، `customer`، `items`، `summary`، `destination`، `last_updated_at`.
- استخدم labels العربية من API. حالات خطوات timeline هي `completed/current/upcoming/cancelled`. عند الإلغاء، percentage وcurrent_step تساويان null، ولا توجد خطوات مستقبلية. أوقف polling عند completed أو cancelled.
- التحديث كل 15–30 ثانية؛ حد المسار العام 60 طلبًا في الدقيقة لكل IP. عند 429 احترم Retry-After. التتبع غير مخبأ، والاستجابة private/no-store.
- ETA تقديرات محفوظة وليست مواعيد مضمونة. أوقات API بصيغة ISO 8601 UTC؛ اعرضها بالمنطقة المطلوبة. التوصيل يحتفظ بـdelivery_from/delivery_to؛ null يعني عدم وجود تقدير موثوق. الطلبات القديمة لم تُمنح تقديرات مصطنعة.
- رمز الضيف credential حساس: استخدم HTTPS في الإنتاج، ولا تضعه في URL أو logs أو analytics أو رسائل الأخطاء. احتفظ به في الذاكرة، أو تخزين جلسة مناسب بحسب احتياج الواجهة. sessionStorage وlocalStorage كلاهما قابلان للقراءة عند XSS؛ لا تعتمد على localStorage كضمان أمني.
- الصلاحية الافتراضية 30 يومًا من إنشاء الطلب، يحددها الإعداد الإداري الخاص orders.guest_tracking_token_days (1–365). تغيير الإعداد يؤثر على الرموز الجديدة فقط. Claim الناجح يبطل الرمز؛ الحساب المالك يستخدم المسار المصادق.
- لا يوجد endpoint عام لاستعادة الرمز المفقود. الرموز القديمة حُوّلت إلى SHA-256 مع الإبقاء على قيمة الرمز التي يحتفظ بها العميل؛ بعد انتهاء الصلاحية تُرفض. Rollback لا يستطيع استرجاع الرموز الخام من hash، ويعيد عمودًا فارغًا فقط؛ استخدم إصلاحًا أماميًا في الإنتاج.
- الرموز لا تُضمّن في إشعارات الطلب الحالية، ولم يُفترض نطاق frontend إنتاجي.
