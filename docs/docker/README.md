# شيشلي — المرحلة الثانية Docker

التاريخ: 2026-10-12. المشروع المستقل `shishli`، والعنوان المحلي `http://127.0.0.1:8088`.

## العزل

- لا أوامر إيقاف أو تعديل للحاويات التابعة إلى emdadat أو hospital.
- الشبكة الداخلية `shishli_private`؛ MySQL وRedis وPHP-FPM لا تنشر منافذ إلى Windows. Nginx وحده متصل أيضًا بـ`shishli_ingress` كي يعمل نشر منفذ loopback على Docker Desktop.
- Volumes: `shishli_mysql`، `shishli_redis`، `shishli_storage`، `shishli_mysql_test`؛ والاختبار المتصفح يستخدم `shishli_e2e_storage`.
- لا ربط بقاعدة XAMPP ولا تعديل للمجلد المرجعي Şişli.
- `.env.docker` و`.env.mysql` و`.env.e2e` أسرار محلية مستبعدة من Git ومن الصور. كلمة مرور MySQL root موجودة في ملف MySQL فقط ولا تُحقن في خدمات PHP للإنتاج. احتفظ بمفتاح APP_KEY خارج النسخة البرمجية مع النسخ الاحتياطية المشفرة؛ تغييره يمنع فك البيانات المشفرة القديمة.

## البناء والتشغيل

```powershell
docker compose -p shishli config --quiet
powershell -ExecutionPolicy Bypass -File docker/start.ps1
docker compose -p shishli ps
node docker/verify.mjs
```

استخدم `.env.docker.example` و`.env.mysql.example` لإنشاء بيئة جديدة مع مفتاح Laravel وكلمات مرور عشوائية؛ يجب أن يطابق MYSQL_PASSWORD قيمة DB_PASSWORD. لا تستبدل ملف الأسرار بعد إنشاء Volume قاعدة البيانات: كلمات مرور MySQL الابتدائية لا تعيد ضبط مستخدمي Volume قائم. لتغيير المنفذ استخدم `SHISHLI_HTTP_PORT` في بيئة PowerShell وحدّث APP_URL ثم أعد إنشاء خدمات المشروع.

PHP 8.3-FPM بإضافات pdo_mysql وphpredis وgd وintl وOPcache. صورة التشغيل تستخدم Composer بدون dev؛ صورة الاختبارات منفصلة. Vue production مبني باستخدام npm ci وNode 22، ويستخدم `/api/v1`؛ Nginx يوجه `/api/*` و`/up` إلى Laravel ويخدم SPA وuploads. توجد gzip وimmutable cache للملفات ذات بصمة البناء. لا تخزن index.html طويلًا.

`start.ps1` يتحقق من هدف الاتصال mysql/shishli قبل الترحيلات، ثم يهيئ أدوار النظام إذا كان جدول roles فارغًا فقط. يرفض `initialize-system.php` أي هدف آخر، ويثبت أن عدد الحسابات لم يتغير. التهيئة الفعلية: 6 أدوار و97 صلاحية و0 حساب؛ لا DatabaseSeeder ولا DemoDataSeeder على قاعدة الموقع.

كل عملية PHP طويلة تعيد بناء config/routes/views cache عند بدء الحاوية. OPcache لا يفحص تغييرات الملفات؛ نشر الكود يستلزم إعادة بناء الصورة وإعادة إنشاء خدمات app/queue/scheduler. Worker يستمع إلى notifications وdefault؛ timeout=60 أقل من retry_after=90، ووقت الإيقاف 90 ثانية. Redis يحتفظ بالـqueue باستخدام AOF everysec؛ تبقى إمكانية فقد آخر ثانية في انقطاع مفاجئ. Scheduler يعمل schedule:work، لكن التطبيق الحالي لا يعرّف مهامًا دورية في routes/console.php.

## الاختبارات المعزولة

```powershell
docker compose -p shishli --profile test up -d --wait mysql-test
docker compose -p shishli run --rm tests
```

للدفعات التي تكرر migrate/rollback، يمكن استخدام Oracle MySQL نفسه على tmpfs بحجم 512 MiB:

```powershell
docker compose -p shishli --profile test up -d --wait mysql-test-fast
docker compose -p shishli run --rm --no-deps -e DB_HOST=mysql-test-fast tests
```

mysql-test-fast مؤقت وبياناته تضيع عند الإيقاف، وهو يستخدم إعداد متانة مخففًا للاختبار فقط. خدمة mysql الفعلية تبقى على Volume دائم بإعداد MySQL الأصلي. اختبارات CLI تستخدم إعدادات testing مستقلة، ولا تستورد إعدادات Redis أو مفتاح APP_KEY من بيئة الموقع؛ PHPUnit يحدد مفتاح الاختبار في phpunit.xml.

الاختبارات تتصل فقط بـ mysql-test وقاعدة shishli_test؛ اختبارات التزامن تنشئ قواعد عشوائية هناك وتحذف قواعدها الخاصة. حساب root موجود في حاوية الاختبار فقط لتلك الاختبارات؛ التطبيق الفعلي يستخدم shishli_app.

رحلة المتصفح تستخدم compose.yaml مع docker/compose.e2e.yaml، منفذ 8090 و`.env.e2e` (APP_ENV=testing، APP_URL=http://127.0.0.1:8090، DB_HOST=mysql-test، DB_DATABASE=shishli_e2e). تحتاج Chrome المثبت وNode مع `--experimental-websocket`. لا تطبق fixture أو DemoDataSeeder على خدمة app الفعلية.

خطوات إعادة اختبار المتصفح بعد نجاح البناء واختبارات MySQL (نُفذت واجتازت التحقق في هذه المرحلة):

```powershell
docker compose -p shishli -f compose.yaml -f docker/compose.e2e.yaml --profile test up -d e2e-app
$e2eContainer = docker compose -p shishli -f compose.yaml -f docker/compose.e2e.yaml --profile test ps -q e2e-app
docker cp docker/e2e-fixture.php "${e2eContainer}:/tmp/e2e-fixture.php"
docker compose -p shishli -f compose.yaml -f docker/compose.e2e.yaml --profile test exec -T e2e-app php /tmp/e2e-fixture.php
docker compose -p shishli -f compose.yaml -f docker/compose.e2e.yaml --profile test up -d e2e-nginx
$env:DESIGN_API_URL='http://127.0.0.1:8090/api/v1'
$env:DESIGN_APP_URL='http://127.0.0.1:8090'
$env:PERF_JOURNEY_PROFILE='docs/docker/evidence/private/journey'
$env:PERF_JOURNEY_OUTPUT='docs/docker/evidence/journey.json'
node --experimental-websocket docker/journey.browser.mjs
node --experimental-websocket docker/browser-smoke.mjs
docker compose -p shishli -f compose.yaml -f docker/compose.e2e.yaml --profile test stop e2e-nginx e2e-app mysql-test
```

تحقق من exit code بعد كل خطوة؛ لا تواصل عند فشل fixture. ملف `.env.e2e` الحالي مُعد محليًا؛ عند إعادة إعداده استخدم أسرار الاختبار المناسبة وتأكد من mysql-test/shishli_e2e وAPP_ENV=testing؛ لا تنسخ اتصال الإنتاج إليه. يحتاج ملف fixture Faker الموجود في صورة tests فقط. رحلة E2E تفحص البحث والخيارات والسلة والعنوان والتوصيل وcash checkout ومنع الضغط المكرر والمطبخ والمندوب والتحصيل والتسوية وresponsive layouts.

لفحص الاتصال الفعلي والـqueue دون حسابات تجريبية:

```powershell
$appContainer = docker compose -p shishli ps -q app
docker cp docker/runtime-probe.php "${appContainer}:/tmp/runtime-probe.php"
docker compose -p shishli exec -T app php /tmp/runtime-probe.php
docker compose -p shishli logs --tail 20 queue
docker compose -p shishli exec -T app php artisan queue:failed --no-interaction
```

تحقق من اكتمال مهمة probe في سجل Worker دون failed_jobs، ومن بقاء users=0 على القاعدة الجديدة قبل إنشاء حساب الإدارة المعتمد. لا تُفسّر healthcheck Scheduler بأنه إثبات مهام أعمال دورية؛ لا توجد مهام مسجلة أصلًا.

## النسخ والاستعادة

```powershell
powershell -ExecutionPolicy Bypass -File docker/backup.ps1
powershell -ExecutionPolicy Bypass -File docker/restore-test.ps1 -BackupDirectory docker/backups/<timestamp>
```

Backup ينسخ SQL transaction-consistent وstorage/app وSHA256. الاستعادة التجريبية محصورة في mysql-test/shishli_restore وفي filesystem مؤقت؛ لا تستبدل بيانات الموقع. للحصول على توافق قاعدة البيانات وuploads أثناء الكتابة، أوقف استقبال الكتابات ضمن نافذة صيانة واسمح للـqueue بالانتهاء قبل backup. أرسل النسخ المشفرة إلى موقع خارجي وحدد retention وRPO/RTO قبل staging الفعلي. Redis ليس مصدر سجلات الأعمال؛ لاستعادة queue بعد كارثة يلزم إجراء مصالحة واضح، لا replay آلي لعمليات دفع أو رسائل.

لا تستخدم docker system prune أو docker volume prune أو docker compose down -v. لإيقاف خدمات الاختبار فقط استخدم أسماء خدمات الاختبار مع project shishli.

## النشر إلى VPS Staging

البنية تستهدف النقل إلى VPS، لكن العنوان الحالي loopback وHTTP. يلزم دومين وTLS reverse proxy وAPP_URL=https://... وSESSION_SECURE_COOKIE=true وTRUSTED_PROXIES حسب IP/CIDR الفعلي. احتفظ بمنفذ التطبيق على loopback خلف وكيل المضيف. الشبكة internal تمنع outbound؛ عند تفعيل SMTP/مزود دفع حقيقي أضف شبكة egress تخص شيشلي فقط وراجع أسرار الاتصال. الإرسال الخارجي والدفع الإلكتروني غير مكتملين حاليًا.

قبل استقبال عملاء: أدخل البيانات التشغيلية المعتمدة، وجهّز حساب الإدارة الحقيقي بإجراء provisioning معتمد، واختبر health والـqueue والتنبيهات والنسخ خارج VPS. لا يوجد حاليًا أمر CLI مخصص لإنشاء أول admin؛ endpoint `/admin/bootstrap` يقرأ جلسة الإدارة ولا ينشئ حسابًا. لم يُنشأ حساب في قاعدة الموقع أثناء هذه المرحلة. لا تستخدم seeder التجريبي. راجع docs/release/OPERATIONAL_READINESS.md. التراجع البرمجي يعيد صورة سابقة ويعيد إنشاء خدمات shishli فقط؛ لا تنفذ migrate:rollback عشوائيًا، فالترحيلات قد تكون غير قابلة للتراجع الآمن. احتفظ بنسخة قاعدة قبل كل نشر وراجع توافق schema مع الإصدار السابق.

مرجع إعداد Laravel: https://laravel.com/framework/docs/deployment
