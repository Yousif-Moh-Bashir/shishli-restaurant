# شيشلي — تقرير المرحلة الثانية Docker

2026-10-12، بتوقيت Africa/Khartoum. **اكتمل التنفيذ والتحقق المحلي. جاهز فنيًا للنقل إلى VPS Staging مع إعدادات المضيف الواردة أدناه.**

رابط الموقع: **http://127.0.0.1:8088**. تحقق توفر المنفذ قبل التشغيل؛ لم يلزم تغييره. اسم Compose Project هو shishli.

## الخدمات والعزل

| الخدمة | الحالة النهائية | منفذ Windows |
|---|---|---|
| nginx | running / healthy | 127.0.0.1:8088 فقط |
| app / PHP-FPM | running / healthy | لا يوجد |
| mysql | running / healthy | لا يوجد |
| redis | running / healthy | لا يوجد |
| queue | running / healthy | لا يوجد |
| scheduler | running / healthy | لا يوجد |
| e2e-app / e2e-nginx / mysql-test / mysql-test-fast | أُوقفت بعد الاختبارات | واجهة 8090 أُغلقت |
| tests | دفعات مؤقتة انتهت؛ النتائج محفوظة | لا يوجد |

الشبكة الداخلية shishli_private تعزل PHP/MySQL/Redis. يتصل Nginx أيضًا بـshishli_ingress لنشر loopback؛ Docker Desktop لم ينشر المنفذ عندما كان Nginx على الشبكة الداخلية وحدها، وأُصلح ذلك ضمن شيشلي.

Volumes مستقلة: shishli_mysql، shishli_redis، shishli_storage، shishli_mysql_test، shishli_e2e_storage. mysql-test-fast يستخدم tmpfs مؤقتًا بحجم 512 MiB. إعداد المتانة المخفف خاص بالاختبار؛ MySQL الموقع بقي على Volume دائم بالإعداد الأصلي.

لم أوقف أو أحذف أو أعيد تشغيل حاويات emdadat/hospital، ولم أغيّر شبكاتها أو Volumes أو منافذها. بعض حاويات emdadat كانت Restarting عند الفحص الأول وظلت كذلك؛ لم أتدخل فيها. لا system prune ولا volume prune ولا down -v. لم يُعدل مجلد Şişli المرجعي ولا قواعد XAMPP.

## تنفيذ البنود السبعة عشر

| البند | النتيجة |
|---|---|
| 1. Dockerfiles | PHP production/testing وNginx مع Vue builder؛ تثبيت من ملفات القفل |
| 2. Compose | مشروع مستقل، الخدمات الست، profiles للاختبار |
| 3. Nginx | SPA، /api/*، /up، storage؛ gzip وimmutable assets وترويسات حماية |
| 4. PHP-FPM / OPcache | PHP 8.3.35؛ OPcache مفعّل فعليًا؛ GD يدعم JPEG/PNG/WebP |
| 5. Vue production | Node 22 / npm ci / Vite 8.3.2؛ 349 modules؛ API نسبي /api/v1 |
| 6. MySQL | Oracle MySQL 8.4.11؛ مستخدم shishli_app مخصص؛ دون منفذ مضيف |
| 7. Redis | Cache وsessions وqueue؛ AOF everysec؛ دون منفذ مضيف |
| 8. Queue | www-data؛ notifications/default؛ timeout=60 أقل من retry_after=90؛ مهمة probe أُنجزت وfailed_jobs=0 |
| 9. Scheduler | schedule:work سليم؛ لا مهام دورية معرفة في التطبيق الحالي |
| 10. Environment | أسرار منفصلة ومستبعدة؛ production/debug=false؛ root password غير محقون في PHP الإنتاج |
| 11. Health checks | الخدمات الست healthy؛ HTTP /up وAPI health نجحا؛ فُحص اتصال DB/Redis فعليًا |
| 12. Build / startup | الصور النهائية تعمل؛ start.ps1 يتحقق من هدف DB قبل migrate ويهيئ metadata عند الحاجة |
| 13. Migrations | 44 ترحيلًا Ran في mysql/shishli؛ لم يحدث rollback على قاعدة الموقع |
| 14. Tests | الحالات الأصلية الـ2117 تحققت على MySQL عبر دفعات وإعادة حالات الإصلاح؛ E2E وbrowser smoke نجحا |
| 15. Backup / restore | SQL + storage + SHA256؛ استعادة معزولة ناجحة للمخطط والـmetadata والبيانات التجريبية ومحتوى ملف |
| 16. Performance | 120 عينة HTTP للموقع، 90 عينة API على بيانات E2E، burst محدود، وChrome وموارد الحاويات |
| 17. Documentation | التقرير وREADME والأدلة والسكربتات التشغيلية |

قاعدة الموقع تحتوي **6 أدوار و97 صلاحية وصفر حسابات**. استُخدم RolePermissionSeeder فقط عند خلو roles، وتحققت idempotence وبقاء عدد الحسابات دون تغيير. لم يُشغّل DatabaseSeeder أو DemoDataSeeder على قاعدة الموقع. تسجيل العميل يحتاج دور customer، لذا هذه metadata ضرورية. بيانات Demo موجودة فقط في mysql-test/shishli_e2e؛ خدماتها أُوقفت.

## الاختبارات والإصلاحات

1320 حالة في الدفعة الأولى و797 في المتبقية. عشرة إخفاقات ضمن هاتين الدفعتين صُححت وأعيدت ضمن مجموعات ناجحة: 222 حالة /1160 assertions، ثم 32/192، ثم 62/319. **التحقق المجمع: 2117 حالة فريدة، 0 إخفاقات غير محلولة، 0 skipped.** هذا تحقق عبر دفعات واستئناف، وليس تشغيلًا متصلًا واحدًا للمجموعة كاملة بعد آخر تعديل. XML الخام قبل الإصلاح محفوظ؛ test-summary.json يتحقق من نجاح لاحق لكل حالة فاشلة.

- اقتباس اسم عمود key في AdminListQuery باستخدام grammar الاتصال أصلح بحث CMS على MySQL.
- أُصلح down() في أربعة ترحيلات: إزالة FK قبل فهرس المندوب، واستعادة فهارس دعم FK قبل إزالة الفهارس المركبة للمنتجات/الصور/الخيارات. up() والمخطط الأمامي لم يتغيرا. migrate ثم rollback كامل نجح على shishli_fix_tests فقط.
- صُححت افتراضات الاختبارات: اقتباس SQLite، ترتيب مفاتيح JSON، SH-0001 الثابت، وصياغة خطأ unique الخاصة بـSQLite. بقيت assertions للأنواع والقيم والقيود وعدم تسريب الأسرار.
- فُعل WebP في GD؛ إنشاء variant أصغر مع حفظ الأصل والشفافية ورفض المسارات غير الآمنة اجتاز الاختبارات.
- 7 اختبارات ترحيلات /40 assertions نجحت على SQLite داخل الذاكرة.
- E2E حقيقي عبر Vue/Axios/Nginx/Laravel/MySQL: بحث، خيارات، سلة، عنوان، quote، cash checkout، منع الضغط المكرر، مطبخ، قبول المندوب وخصوصيته، استلام، توصيل، تحصيل، completion وتسوية. **18 صفحة و210 فحوص responsive؛ passed**.
- browser smoke: 14 زيارة للهاتف وسطح المكتب؛ دون overflow أو أخطاء JavaScript. نجحت فحوص HTTP/auth ومنع .env وPHP مباشر وgzip/immutable/security headers.
- اختبارات منطق الواجهة المحلية نجحت: polling وorder access وcheckout location وphone (حالتان). Pint نجح.

## النسخ والاستعادة

النسخة الأحدث: docker/backups/20261012-214208/، مستبعدة من Git. استُعيدت إلى mysql-test/shishli_restore: **63 جدولًا، 44 سجل migrations، 6 أدوار، 97 صلاحية، 0 حسابات**. استُعيد storage/app مؤقتًا وراجعت SHA256.

اختبار إضافي قارن E2E قبل وبعد الاستعادة: **13 مستخدمًا تجريبيًا، 30 طلبًا، إجمالي 1767.00، وسجلّا تحصيل** وقت أخذ نسخة الاختبار؛ تطابقت القيم. استُعيد أيضًا محتوى ملف storage تجريبي. لم تستبدل الاختبارات بيانات الموقع. احفظ APP_KEY والأسرار خارج المستودع مع نسخة مشفرة مستقلة؛ لم تُنسخ الأسرار إلى التقارير.

## نتائج الأداء

30 طلبًا لكل endpoint بعد warmup؛ الزمن يشمل قراءة HTTP body؛ localhost دون throttling. قاعدة الموقع لا تحتوي كتالوج المطعم الحقيقي.

| المسار / البيئة | Median ms | p95 ms |
|---|---:|---:|
| / — الموقع | 3.99 | 5.43 |
| /up — الموقع | 9.00 | 19.25 |
| /api/v1/health — الموقع | 6.94 | 10.19 |
| /api/v1/branches — الموقع | 10.36 | 25.15 |
| branches — E2E | 18.55 | 41.06 |
| home — E2E | 42.42 | 55.63 |
| menu — E2E | 29.02 | 33.52 |

Burst لـ /up في E2E: 64 طلبًا بتزامن 8، نحو **185.6 request/s**، p95=51.17 ms. اختبار قصير لفحص الصحة، وليس سعة checkout أو SLA على VPS. E2E يستخدم APP_ENV=testing وصورة dev dependencies؛ أرقامه ليست قياس production كامل ببيانات المطعم.

Chrome lab للرئيسية: LCP هاتف 390px **2.13s** وCLS=0؛ سطح مكتب 1366px **0.45s** وCLS≈0.00036. عينة لكل صفحة/عرض؛ لا field INP ولا إثبات Safari/iOS.

OPcache مفعّل، validate_timestamps=0، hit rate **99.41%** دون OOM/hash/manual restarts عند الفحص. لقطة RAM للخدمات الست نحو **616 MiB**: PHP 47.81، Nginx 10.26، MySQL 481.6، Redis 8.80، Queue 35.56، Scheduler 31.86. ليست حدودًا أو قياس حمل أقصى. Vue المحلي بُني في 3.51 ثانية؛ Docker production build نجح أيضًا.

## الملفات

البنية الجديدة: .gitignore، .dockerignore، compose.yaml، .env.docker.example، .env.mysql.example؛ ملفات docker/php وdocker/nginx؛ docker/compose.e2e.yaml؛ سكربتات start/initialize/backup/restore/runtime/OPcache/HTTP/performance/browser/test-summary واختبارات التشغيل في docker؛ README والتقرير وأدلة JSON/XML/text في docs/docker/evidence.

حُدّث docs/release/RELEASE_BLOCKERS.md لربط حالة Docker الجديدة بالتقرير مع إبقاء نتائج التدقيق السابق المؤرخة.

الملفات الوظيفية المعدلة:

- back-end/app/Services/Admin/AdminListQuery.php
- back-end/database/migrations/2026_09_29_150008_complete_products_schema.php
- back-end/database/migrations/2026_09_29_170147_complete_product_images_schema.php
- back-end/database/migrations/2026_09_29_215059_complete_product_options_schema.php
- back-end/database/migrations/2026_10_08_002358_add_internal_delivery_domain.php
- back-end/tests/Feature/AdminOperationsTest.php
- back-end/tests/Feature/Api/V1/Admin/ProductOptionGroupTest.php
- back-end/tests/Feature/Api/V1/DeliveryQuoteTest.php
- back-end/tests/Feature/Api/V1/ProductFiltersTest.php
- back-end/tests/Feature/CheckoutTest.php
- back-end/tests/Feature/OrderTransactionTest.php
- back-end/tests/Feature/PaymentWebhookTest.php

ملفات .env.docker/.env.mysql/.env.e2e مستبعدة. أُعيد توليد front-end/dist المستبعد أصلًا؛ لم يتغير كود Vue الوظيفي أو dependencies أو ملفات القفل. لم يُنشأ commit أو نشر خارجي.

## المتبقي لـVPS Staging

**GO فنيًا لنقل الحاويات إلى VPS Staging.** يلزم إعداد المضيف الحقيقي: دومين وHTTPS وAPP_URL وSESSION_SECURE_COOKIE=true وTRUSTED_PROXIES حسب IP/CIDR، أسرار مستقلة، نسخ مشفرة خارج VPS مع retention وRPO/RTO، ومراقبة وتنبيهات health/queue/القرص. لا تشغيل profiles الاختبار على VPS العام.

حساب الإدارة الحقيقي وبيانات المطعم تحتاج provisioning معتمدًا؛ لا يوجد CLI مخصص لإنشاء أول admin، و/admin/bootstrap يقرأ الجلسة ولا ينشئ حسابًا. راجع docs/release/OPERATIONAL_READINESS.md. الدفع الإلكتروني والإرسال الخارجي غير مفعّلين؛ تفعيلهما يحتاج adapters وتجارب وشبكة egress تخص شيشلي. Scheduler لا يملك مهام أعمال دورية معرفة أصلًا.

تعطل Docker Desktop/containerd SIGBUS وأخطاء I/O عولج **خارج المشروع بواسطة المستخدم**؛ لم أعد تشغيل المحرك. بعد عودته اكتملت الاختبارات والاستعادة. لا عائق Docker مفتوح عند الفحص النهائي. التراجع الإنتاجي يحتاج مراجعة بيانات واستعادة مدروسة؛ لا تجعل migrate:rollback أو حذف Volumes إجراء نشر تلقائيًا.

الأدلة: services.json، test-summary.json، fixes-final.xml، platform-fixes-final.xml، media-regression-final.xml، sqlite-migration-regression.xml، journey.json، browser-smoke.json، http-performance.json، seeded-performance.json، runtime-probe.json، opcache.txt، resources.txt. دليل التشغيل: README.md.
