# Production environment checklist

2026-10-11. نموذج الإنتاج: [`back-end/.env.production.example`](../../back-end/.env.production.example). النموذج لا يحتوي أسرارًا ولا يفعل أي نشر. لا تنسخ `.env` المحلي للإنتاج. القياسات الحالية [محلية ومعقمة](evidence/local-configuration.json)، وليست إثباتًا لإعداد VPS.

| المجال | الإجراء المطلوب قبل استقبال العملاء | التحقق الحالي |
|---|---|---|
| APP_ENV / APP_DEBUG | production / false | المحلي local/debug=true؛ يجب تغييره في بيئة النشر، وليس في جهاز التطوير |
| APP_KEY | مفتاح جديد صحيح محفوظ كسر؛ عدم تغيير مفتاح بيئة قائمة عشوائيًا | وجود المفتاح المحلي تحقق كـ boolean فقط؛ لا قيم في التقرير |
| APP_URL | HTTPS للدومين الحقيقي؛ media URLs من نفس الإعداد | الحالي غير HTTPS؛ hostname النموذج placeholder |
| MySQL | مستخدم تطبيق مخصص، كلمة مرور، أقل صلاحيات مناسبة؛ عدم نشر منفذ DB للعامة | MariaDB 10.4.25 اختُبر؛ Oracle MySQL المستهدف NOT VERIFIED |
| SESSION | database، cookie secure/httpOnly، encryption، domain مناسب | النموذج يضبطها؛ تسجيل الدخول الفعلي يستخدم Bearer |
| Sanctum | استمرار Bearer PAT؛ مراجعة عمر التوكنات وإبطالها عند إيقاف الحساب | لا stateful SPA middleware؛ expiry الافتراضي null؛ لا تعتمد على متغير expiry غير مقروء |
| CORS | API خلف نفس الدومين: origins فارغة. دومين منفصل: HTTPS origin محدد بلا slash نهائي | أضيف allowlist وexposed Retry-After/Request-ID واختبارات؛ لا cookies credentials |
| Trusted proxies | IP/CIDR للوكيل الحقيقي؛ وصول Laravel من الوكيل فقط؛ تنقية الرؤوس عند الوكيل | إعداد قابل للضبط، default فارغ؛ proxy spoofing tests نجحت |
| HTTPS | شهادة وتجديد، تحويل HTTP، فحص scheme وروابط الصور وmixed content | NOT VERIFIED على staging |
| Cache / rate limiting | database كبداية؛ جداول cache/locks؛ متجر مشترك بين replicas؛ لا array للإنتاج | migrations موجودة، named limits مفعلة؛ لا Redis مضاف |
| Queue | database وworker مُراقب؛ تشغيل طابور notifications تحديدًا إذا فُعل الإرسال | صفوف jobs/failed_jobs موجودة؛ تشغيل supervisor/restart/retry في الإنتاج NOT VERIFIED |
| Scheduler | لا مهام مجدولة حاليًا؛ لا ادعاء أن cron يحل وظائف لم تُنفذ | `schedule:list`: لا مهام. جدولة backup/pruning تحتاج قرارًا منفصلًا |
| Filesystem | تخزين uploads دائم؛ storage:link؛ write لstorage/bootstrap/cache فقط حسب user التشغيل | public path وGD/WebP موجودان محليًا؛ صلاحيات Linux NOT VERIFIED |
| Logging | daily + warning/retention؛ حماية logs من HTTP؛ عدم تسجيل headers/tokens/passwords | API errors عامة وrequest IDs؛ local log level debug طبيعي للتطوير |
| Mail | اختيار SMTP/مزود وتجربة sandbox مع مستقبلين مضبوطين إذا لزم | mail=log لا يمثل إرسالًا؛ قناة email معطلة حتى لو ضُبط SMTP |
| SMS/WhatsApp | مزود، adapter، templates معتمدة، queue، فشل وإعادة محاولة | providers فارغة وقنوات معطلة؛ تغييرات env وحدها لا تكفي |
| Payment | مزود حقيقي وتجربة sandbox وتوقيع webhook ومبالغ/عملة/تكرار | providers فارغة؛ cash/network offline فقط في checkout |
| Webhooks | secret من منصة النشر، raw signature قبل mutation، timestamp/body limits، نطاق replay | اختبارات المزود الوهمي نجحت؛ توقيع المزود الحقيقي NOT VERIFIED |
| Uploads | توافق حدود proxy/PHP مع ملفات 5MiB وحد طلب 10 صور؛ منع تنفيذ الملفات في uploads | validation MIME/image موجودة؛ proxy limits NOT VERIFIED |
| Frontend | Vite production build؛ API `/api/v1` أو HTTPS API العام؛ لا أسرار في VITE_* | البناء النهائي نجح مع API نسبي؛ development server غير مناسب للإنتاج |
| Backup / restore / rollback | قاعدة + uploads + الأسرار؛ تجربة restore وrollback؛ مراقبة صحة التطبيق | لم تُنفذ على VPS؛ شرط إطلاق |

## تشغيل المرحلة الثانية

استخدم PHP-FPM/OPcache وreverse proxy، وقدّم `front-end/dist` مع fallback لمسارات Vue. وجّه `/api/*` بما فيه `/api/user` و`/api/v1/*` إلى Laravel، ووفر `/storage/*` وفق قرارات الاستضافة. طَبّق gzip/Brotli وكاش immutable للملفات ذات hash فقط؛ لا تكاش أسعار/حالة طلب دون سياسة invalidation مثبتة.

الأوامر المستقبلية: `migrate --force` على اتصال الإنتاج المتحقق منه؛ **لا migrate:fresh**. اختبارات هذه الجلسة تبني قواعد عشوائية منفصلة ولا تعطي إذنًا لإعادة ضبط قاعدة المطعم. `DatabaseSeeder` يتضمن catalog/branch defaults، و`BranchSeeder` يستعمل updateOrCreate؛ راجع أثره قبل أي تشغيل على بيانات مخصصة. لا تشغل DemoDataSeeder في production. يلزم إجراء آمن لإنشاء أول super_admin؛ لا ينشئه production seeding الحالي تلقائيًا.

بناء config/route caches نجح محليًا في `storage/framework/testing/release-*` ومسح بعدها. في النشر يجب بناؤها **بعد** حقن أسرار وإعدادات البيئة الحقيقية، وإعادة تشغيل workers عند تغيير الإعدادات. لا تنشر cache محليًا أو audit manifests أو browser profiles أو tests/Support servers. Docker Engine لم يكن شغالًا؛ لم يُنفذ build/run لحاويات.

راجعت توافق الإعدادات مع المصدر المثبت و[توثيق Laravel للـ CORS](https://laravel.com/framework/docs/13.x/routing#cross-origin-resource-sharing-cors) و[الوكلاء الموثوقين](https://laravel.com/framework/docs/13.x/requests#configuring-trusted-proxies). الاختبارات المحلية لا تثبت صحة طوبولوجيا الوكيل على VPS.
