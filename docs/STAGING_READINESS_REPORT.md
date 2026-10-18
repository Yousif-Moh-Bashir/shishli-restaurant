# SHISHLI — Phase 3C: CI Integration & Staging Readiness

التاريخ: 16 أكتوبر 2026. الفرع المستهدف: `feature/ghcr-publishing`.

**القرار: غير جاهز لأول نشر Staging بعد.** تم تقوية CI وإضافة اختبارات تكامل حقيقية، لكن يلزم تنفيذ النسخة المعدلة على GitHub والتحقق من حماية الفرع، ومعالجة عائق PHP الأمني، وتجهيز البيئة التشغيلية أدناه. لا يمثل نجاح اختبار SQLite المحلي إثباتًا لنجاح MySQL أو Redis.

## نطاق العمل والملفات

راجعت [GHCR_SETUP_REPORT.md](GHCR_SETUP_REPORT.md)، [PHP_RUNTIME_SECURITY_DECISION.md](PHP_RUNTIME_SECURITY_DECISION.md)، نتائج الأمان وملفات CI وGHCR والاختبارات وإعدادات التطبيق والتشغيل والنسخ الاحتياطي.

ملفات هذه المرحلة فقط:

| الملف | التغيير |
| --- | --- |
| `.github/workflows/ci.yml` | تحقق من خدمات معزولة وإصداراتها وقاعدة فارغة؛ migrations؛ مجموعتا اختبار؛ منع التخطي؛ تدقيق JUnit؛ حفظ الأدلة |
| `back-end/tests/Feature/CiServicesIntegrationTest.php` | خمسة اختبارات MySQL/Redis فعلية محمية من التشغيل العارض محليًا |
| `.github/scripts/check-laravel-results.py` | رفض تقارير ناقصة أو فاشلة أو متخطاة واشتراط 19 فئة اختبار أساسية |
| `.github/scripts/test_check_laravel_results.py` | سبعة اختبارات لأداة التدقيق، تتضمن تقارير مفقودة/تالفة وحالات تزامن ناقصة |
| `docs/STAGING_READINESS_REPORT.md` | هذا التقرير |

بقية تغييرات Phase 3B الموجودة مسبقًا محفوظة. لم أعدل سياسة Trivy أو Workflow GHCR أو Dockerfiles أو Compose في هذه المرحلة. لم أنفذ نشرًا أو SSH أو commit أو push، ولم أشغل/أوقف/أعد إنشاء الحاويات أو أستخدم قواعد البيانات المحلية أو Volumes. الاختبارات المحلية تستخدم SQLite في الذاكرة.

## ما كان CI ينفذه وما أضيف

كان Laravel job يشغل **كامل** مجموعتي Unit وFeature بواسطة `php artisan test --compact` ضد خدمة MySQL 8.4، مع تفعيل علمي اختبارات التزامن. إعداد SQLite في `phpunit.xml` لا يستخدم `force` لاتصال قاعدة البيانات، ولذلك تتقدم عليه متغيرات MySQL الموروثة من CI. كان Redis 7.4 موجودًا، لكن فحصه اقتصر على ping؛ المخزن والجلسات الافتراضية للاختبارات array والطابور sync، فلا يثبت ذلك تكامل Laravel مع Redis.

| المسار | تغطية موجودة فعلًا | تقييم/تحسين هذه المرحلة |
| --- | --- | --- |
| الطلبات | `OrderLifecycleTest`، `OrderAccessTest`، وحدات state machine | انتقالات وصلاحيات وملكية الطلب والوصول؛ تشغيل كامل دون تكرار اختبارات موجودة |
| النقد | `CashPaymentTest`، `PaymentPolicyTest` | المبلغ الموثوق، checkout متكرر، صلاحيات التأكيد والتحصيل؛ إلزام وجود النتائج |
| التوصيل | `InternalDeliveryTest` | سير التسليم والتحصيل والتسويات والملكية وتعارض التحصيل المتكرر؛ موجود بالفعل |
| الصلاحيات | `RolesAndPermissionsTest`، `AdminIntegrationSecurityTest`، `AuditAccessBoundaryTest` واختبارات أخرى ضمن المجموعة الكاملة | فرض وجود الفئات الأساسية مع استمرار تشغيل بقية الاختبارات |
| التزامن | Inventory؛ DriverAcceptance حالتان؛ CouponCheckout حالتان؛ AdminStaff؛ AdminRoleMysqlValidation؛ OrderPlacementMysql | عمليات MySQL مستقلة وقواعد اختبار عشوائية؛ لا استبدال لها بمحاكاة SQLite، ولا قبول skip في CI |
| إعداد النشر | `ProductionConfigurationTest` | CORS ومنشأ الطلب وقراءة Retry-After وX-Request-ID وحدود الثقة في proxy؛ لا يعوض اختبار TLS على البيئة |
| مزود الدفع | PaymentWebhook واختبارات الدفع الأخرى | حالات التطبيق بمزود اختبار؛ لا إثبات لتكامل بوابة حقيقية أو صلاحية credentials |
| Redis | سابقًا ping فقط | جديد: cache وملكية lock، جلسة مشفرة تستعاد من store جديد، إرسال job حقيقي بعد commit ومعالجته، وعدم إرساله بعد rollback |

ملف `Api/V1/BranchMySqlTest.php` الحالي اختبار GET بسيط، وليس دليل تكامل MySQL. أزيل العلم غير المستخدم `BRANCH_MYSQL_TEST` من CI؛ لم تُحذف اختبارات موجودة. قائمة 19 فئة هي حد أدنى لمنع اختفاء التغطية الأساسية وليست قياسًا لنسبة تغطية الأسطر أو ضمانًا لكل سيناريو.

## العزل وmigrations ومسار الفشل

1. كل Laravel job على GitHub ينشئ خدمتي `mysql:8.4` و`redis:7.4-alpine` في runner مؤقت؛ كلمة المرور المعروفة فيه خاصة بهذه الخدمة ولا تصلح كسر Staging.
2. قبل migrations يُشترط `GITHUB_ACTIONS=true` و`APP_ENV=testing` واتصال localhost وقاعدة `shishli_ci_test`، والتحقق من MySQL 8.4 وRedis 7.4 ومن خلو القاعدة من الجداول. قاعدة غير فارغة تفشل بدل حذف بياناتها.
3. تشغيل `migrate --force --no-interaction` مرتين يختبر إنشاء المخطط من الصفر وإعادة التنفيذ دون migrations جديدة، ثم يحفظ `migrate:status`. لا seed لبيانات محلية/إنتاجية. اختبارات التزامن تنشئ قواعد مؤقتة بأسماء عشوائية وتحذفها داخل خدمة CI فقط.
4. المجموعة `ci-services` تعمل أولًا على المخطط الجديد، ثم كامل Unit/Feature باستثنائها. الاثنتان تستخدمان `--fail-on-skipped`. الاختبارات الجديدة تتأكد من هوية البيئة والاتصالات وprefix قبل استعلامات البيانات؛ لا RefreshDatabase فيها قبل هذه الحواجز. تستخدم مفاتيح وطوابير عشوائية وتنظف مواردها فقط دون Redis flush؛ قناة SMS معطلة صراحة لمنع إرسال خارجي.
5. مدقق JUnit يشترط تقريرين صالحين وغير فارغين وغياب errors/failures/skips، ووجود الفئات الأساسية والحد الأدنى لحالات datasets. لا `continue-on-error` للخطوات الإلزامية. فشل migrations أو الاختبار أو التدقيق يفشل Laravel job.
6. تحفظ الأدلة لمدة 7 أيام في artifact باسم `laravel-ci-evidence-<run_id>-<run_attempt>` حتى عند الفشل؛ التحذير من ملف artifact مفقود لا يحول اختبارًا فاشلًا إلى ناجح.

يبقى باقي CI: Composer validation وlocked install وplatform requirements وPint؛ اختبارات Vue الموجودة وبناء Node 22؛ التحقق من Compose وبناء صور الإنتاج دون تشغيل خدمات أو رفع صور.

## الدمج وGHCR

حُفظت أسماء الفحوص الثلاثة دون تغيير:

- `Laravel / PHP 8.3 / MySQL 8.4`
- `Vue / Node 22`
- `Docker Compose / production builds`

وفق وصف المستخدم حماية `master` قائمة بالفعل بهذه الفحوص. **لم أتحقق من إعداد GitHub البعيد بصورة مستقلة** لعدم توفر اتصال GitHub موثق أثناء التنفيذ؛ طُرح موصل GitHub للمساعدة في ذلك. تغيير YAML لا يفرض branch protection بنفسه. يجب على المراجع فتح Settings → Rules/Branches والتأكد من required checks الثلاثة ومنع تجاوزها ضمن سياسة المستودع، ومراجعة PR حديث يطابق SHA الحالي. لا تستخدم نجاح تشغيل قديم دليلاً للنسخة المعدلة.

Workflow GHCR وبوابة التحقق من CI لنفس commit لم يتغيرا. يبقى Trivy مانعًا للنشر عند HIGH/CRITICAL، دون ignore-unfixed أو استثناءات جديدة. نتائج Phase 3B التاريخية: PHP Bookworm **77 HIGH و3 CRITICAL**، Nginx **0/0**. دراسة PHP وجدت Trixie 54/1، ولم تعتمد Alpine رغم عداد 0/0 بسبب دليل libxml2 غير المصحح. هذه أرقام التقرير السابق وليست إعادة فحص في Phase 3C؛ يلزم scan حديث قبل أي إطلاق.

## نتائج التحقق الفعلية

| التحقق | النتيجة وحدودها |
| --- | --- |
| Pint dirty + الملف الجديد صراحة | ناجح |
| PHP syntax للملف الجديد | ناجح |
| اختبارات أداة تدقيق JUnit | 7 ناجحة؛ تقبل التقرير الكامل وترفض التخطي والفشل والنقص والتلف |
| actionlint لـCI وGHCR | ناجح؛ shellcheck وpyflakes غير متاحين ومعطلان في هذا الفحص فقط |
| `git diff --check` | ناجح؛ تحذيرات Windows عن LF/CRLF فقط |
| اختبارات Laravel محليًا | 125 ناجحة، 798 assertion، و5 skipped مقصودة لاختبارات خدمات Actions؛ 291.62 ثانية على PHP 8.3.29 وSQLite في الذاكرة، دون اتصال MySQL/Redis محلي |
| بوابة ترخيص GHCR | 14 اختبارًا ناجحًا، تشمل رفض PR والـSHA القديم ومحاولة CI القديمة والمهام المتخطاة |
| مدقق محتويات الصور | 5 اختبارات ناجحة لأداة التدقيق؛ ليست إعادة بناء أو scan للصورة بهذه المرحلة |
| اختبارات الهاتف | اختباران ناجحان على Node 20.19 المحلي؛ Node 22 يبقى مطلوبًا في CI |
| اختبارات delivery-location الحالية | نجحت السكربتات الثلاثة: checkout-location وlocation-provider وdelivery-quote-races؛ أعيدت خارج sandbox بعد منع Windows الوصول لمسار Node، دون تشغيل خدمات التطبيق |
| MySQL 8.4/Redis 7.4 وmigrations داخل Actions | مُعدّة ولم تُنفذ النسخة المعدلة على GitHub في هذه الجلسة؛ لا تُسجل كنجاح |
| إلزام checks في حماية master | يحتاج مراجعة إعداد المستودع الفعلي |
| HTTPS ونسخ/استعادة Staging | غير منفذة؛ لا توجد بيئة Staging في نطاق العمل |

أمر Laravel المحلي: `php artisan test --compact --filter='CashPaymentTest|InternalDeliveryTest|OrderLifecycleTest|OrderAccessTest|PaymentPolicyTest|ProductionConfigurationTest|CiServicesIntegrationTest'` مع `APP_ENV=testing` و`DB_CONNECTION=sqlite` و`DB_DATABASE=:memory:` و`DB_URL` فارغ وcache/session من نوع array وطابور sync و`CI_SERVICES_INTEGRATION=0`. تقرير JUnit المحلي في `%TEMP%/shishli-phase3c-local.xml`. النتيجة 130 حالة إجمالًا، منها 125 منفذة ناجحة؛ الخمس المتخطاة ليست دليل نجاح للتكامل. لم أشغل مجموعة MySQL concurrency محليًا حفاظًا على الخدمات والبيانات.

## قائمة جاهزية البيئة قبل أول نشر

| المجال | المطلوب لقبول Staging | الحالة الحالية |
| --- | --- | --- |
| HTTPS | نطاق وDNS وشهادة موثوقة وسلسلة صحيحة وتجديد مُراقب وتحويل HTTP إلى HTTPS؛ اختبار المتصفح والهاتف؛ حصر MySQL/Redis داخليًا | لم يُجهز/يُتحقق على Staging؛ HTTPS المحلي ليس إثباتًا |
| proxy وURLs | APP_URL وواجهة Vue على العنوان النهائي؛ TRUSTED_PROXIES بعناوين الوكيل الفعلية فقط؛ تحقق scheme وIP والروابط ورفع الوسائط | اختبارات الإعداد موجودة؛ القيم الفعلية معلقة |
| CORS | CORS_ALLOWED_ORIGINS قائمة origins الدقيقة مع scheme/port؛ اختبار allowed/denied preflight وAuthorization وX-Request-ID وRetry-After | إعداد التطبيق يمنع wildcard التلقائي خارج local/testing؛ لم تختبر بيئة حقيقية |
| APP_KEY | توليد مفتاح فريد خارج البناء لكل بيئة، حفظه ثابتًا وسريًا ونسخه احتياطيًا مشفرًا؛ خطة rotation مع APP_PREVIOUS_KEYS عند الحاجة | مفتاح CI التجريبي لا يستخدم للنشر؛ مفتاح Staging لم يُجهز |
| أسرار runtime | APP_DEBUG=false؛ حقن أسرار DB وRedis والتكاملات عند التشغيل؛ فصل Staging عن الإنتاج؛ لا env/keys داخل الصور/المستودع/الأصول أو logs | آلية Compose المستقل موجودة؛ قيم وأذونات البيئة تحتاج تحققًا |
| تهيئة Laravel | cache config/routes/views بعد حقن إعدادات runtime؛ وصول كتابة محدود إلى storage/bootstrap/cache؛ إعادة تشغيل workers عند تحديث الإعداد | entrypoint يدعم ذلك؛ تحقق عند تشغيل البيئة |
| بيانات وصلاحيات | DB ومستخدم محدودان منفصلان عن الإنتاج؛ migrations بمراجعة؛ إنشاء أول مسؤول بطريقة آمنة موثقة؛ لا DemoDataSeeder في Staging المشتركة | إجراء تهيئة المسؤول ومراجعة الصلاحيات مطلوبان |
| طوابير وإشعارات | worker دائم لطابور notifications مع مراقبة التقدم والفشل؛ sandbox للمزودين أو إبقاؤهم معطلين ضمن نطاق cash-only واضح | اختبارات Redis لا تثبت المزودين؛ SMS/WhatsApp/email معطلة افتراضيًا |
| نسخ احتياطي | SQL متسق + storage/app + APP_KEY/config اللازم لاسترجاع المحتوى المشفر، حفظ أسرارها منفصلًا؛ تشفير ونقل خارج المضيف وأذونات وretention وتحديد RPO/RTO ومسؤول | scripts محلية متاحة، وليست نظام نسخ إنتاجي جاهزًا |
| استعادة | استعادة نسخة مشفرة في بيئة منفصلة، تحقق checksum وmigrations والطلبات والمدفوعات والوسائط والصلاحيات، تسجيل زمن/نقطة الاستعادة، ثم اعتماد الإجراء | لم تنفذ تجربة استعادة Staging |
| اتساق النسخ | تحديد نقطة متسقة بين SQL والملفات، وإيقاف الكتابات/تفريغ الطوابير عند اللزوم؛ لا إعادة تشغيل تحصيل نقدي/دفع بلا تحقق idempotency | يلزم runbook وتجربة فعلية |
| مراقبة | uptime/TLS expiry وHTTP5xx والlatency وDB/disk وRedis وworker last-success/failed_jobs والنسخ؛ logs تحجب الرموز وبيانات العملاء مع request ID؛ مالك للتنبيهات | غير مثبتة على بيئة فعلية |
| healthchecks | /up يثبت إقلاع Laravel فقط؛ فحص DB/Redis داخلي وحالة worker الفعلية دون كشف أسرار للعموم | مطلوب اختبار قبول تشغيلي؛ scheduler/queue heartbeat وحدهما لا يثبتان إنجاز العمل |
| إصدار وrollback | صور GHCR خاصة وصلاحية pull محدودة، SHA/digest محدد للصورتين؛ عدم استخدام latest وحده؛ migration متوافق مع rollback وخطة استعادة | محجوب أمنيًا قبل نشر الصور |

ملاحظات النسخ: أدوات `docs/docker` الحالية مفيدة كبداية؛ سكربت SQL إلى `/tmp` وحده لا يقدم تشفيرًا أو حفظًا خارج المضيف أو retention. إعداد Redis AOF everysec قد يفقد آخر ثانية ولا يغني عن حماية بيانات الأعمال. لا تُشغَّل سكربتات restore المحلية كجزء من هذه المهمة.

## خطوات القبول التالية بالترتيب

1. مراجعة التغييرات ثم commit/push وPR بواسطة المستخدم أو بعد موافقته؛ لم تنفذ هذه الأوامر هنا.
2. تشغيل CI للـSHA الجديد ومراجعة مرحلتي migrations، والخمسة ci-services، وكامل الاختبارات والتزامن، وتدقيق JUnit، وVue وDocker. تنزيل artifact وتوثيق run URL وSHA والنتائج الفعلية؛ الفشل يحتاج إصلاحًا، ولا يسمح باستبداله بـskip.
3. التحقق من required checks وحظر الدمج عند فشل إحداها. لم تُختبر محاولة دمج فاشلة على GitHub هنا.
4. معالجة نتائج PHP استنادًا إلى دراسة Phase 3B وإعادة فحص الصور وقاعدة Trivy الحديثة دون تعطيل gate؛ GHCR يظل محجوبًا حتى ذلك.
5. تجهيز بيئة Staging وأسرارها وHTTPS والنسخ والمراقبة وإجراء استعادة مستقلة ناجحة، ثم اعتماد smoke test للطلبات النقدية والتوصيل والتحصيل والأدوار من المتصفح والهاتف على الإصدار المحدد.

لا يوجد تصريح بالنشر ضمن هذه المرحلة؛ استكمال إعدادات CI محليًا لا يزيل العوائق الأمنية والتشغيلية أعلاه.
