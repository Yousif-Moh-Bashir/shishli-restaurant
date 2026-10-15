# تقرير إعداد CI — شيشلي

التاريخ: 2026-10-15. المستودع المستهدف: `Yousif-Moh-Bashir/shishli-restaurant`، والفرع الرئيسي `master`.

## النتيجة

أُضيف `.github/workflows/ci.yml` لتشغيل GitHub Actions عند الدفع إلى `master` وعند فتح أو تحديث Pull Request يستهدف `master`. يتضمن ثلاث مهام مستقلة على `ubuntu-24.04`، دون نشر أو رفع صور. لم يُنفّذ `git add` أو `commit` أو `push`، ولذلك لم يُشغّل Workflow على GitHub بعد.

## ما كشفه الفحص

- Laravel موجود في `back-end`؛ ملفا `composer.json` و`composer.lock` موجودان. متطلب PHP هو 8.3، والإطار المقفل Laravel 13.33.0. PHPUnit وPint متاحان ضمن أدوات التطوير.
- `back-end/phpunit.xml` يستخدم SQLite داخل الذاكرة افتراضيًا. قيم الاتصال الخارجية غير الإجبارية يمكن تجاوزها ببيئة CI. اختبارات التزامن الخاصة بالمخزون والمندوبين والكوبونات والموظفين تنشئ قواعد MySQL مؤقتة ذات أسماء عشوائية وتحتاج حسابًا يستطيع إنشاء قواعد اختبار.
- Vue موجود في `front-end` مع `package-lock.json`. أمر الاختبار المسجل هو `npm run test:delivery-location`، ويشغّل ملفات الموقع والمزوّد وسباقات نتائج التغطية. يوجد أيضًا `tests/phone.test.mjs` ويعمل باستخدام Node test runner.
- لا يوجد أمر عام باسم `npm test`، لذلك لم يُستخدم أو يُخترع في Workflow.
- اختبارات المتصفح الحالية تعتمد على `C:/Program Files/Google/Chrome/Application/chrome.exe` في `browser-client.mjs`. لم تُضمّن في مهمة Linux، ولم تُغيّر لتوسيع نطاق هذه المهمة. كذلك أدوات reconciliation تعتمد على بيانات وتقارير وخوادم محلية سابقة، وليست اختبارات مستقلة مناسبة لهذه المهمة.
- Compose يحتاج `.env.docker` و`.env.mysql` غير المتتبعين. توجد أمثلة متتبعة لكل منهما، وملفات Docker الفعلية هي `docker/php/Dockerfile` و`docker/nginx/Dockerfile`.
- كشف Pint مخالفة مسافات واحدة في `GeoServiceTest.php`. أُصلحت بالمنسق فقط دون تغيير القيم أو التحقق أو معادلة المسافة.

## مهام Workflow

| المهمة | الإعداد والفحوص |
| --- | --- |
| Laravel | PHP 8.3 مع Composer 2 وامتدادات المشروع، `composer validate --strict --no-check-publish`، ثم `composer install` من القفل و`composer check-platform-reqs`، وفحص `vendor/bin/pint --test` وتشغيل `php artisan test --compact` للمجموعة الكاملة |
| Vue | Node 22، تخزين npm المؤقت مرتبط بـ`front-end/package-lock.json`، ثم `npm ci` واختبارات الموقع والهاتف و`npm run build` باستخدام `/api/v1` |
| Docker | إنشاء ملفات env مؤقتة من الأمثلة داخل runner فقط، ثم `docker compose -p shishli-ci --profile test config --quiet` وبناء `app nginx` دون تشغيل الخدمات أو رفع الصور |

مدة Laravel وDocker القصوى 30 دقيقة لكل مهمة؛ Vue مدتها 15 دقيقة. تشغيل أحدث لنفس الفرع أو PR يلغي التشغيل الأقدم. لا توجد `continue-on-error` أو آلية تخفي فشل الاختبارات أو التنسيق.

## عزل البيانات والأمان

- الصلاحية الوحيدة لـ`GITHUB_TOKEN` هي `contents: read`، وCheckout لا يحتفظ ببيانات الاعتماد في إعدادات Git.
- مراجع Checkout وsetup-node وsetup-php مثبتة على SHA كامل، تم التحقق من مطابقتها للمراجع العامة `v4` و`v4` و`v2` وقت الإعداد.
- المهمة تستخدم `push` و`pull_request`؛ لا تستخدم `pull_request_target`، ولا تتعامل مع أحداث PR بصلاحيات كتابة.
- MySQL 8.4 وRedis 7.4 يعملان كخدمات جديدة خاصة بالمهمة على GitHub، مع health checks. لا يتصل Workflow بجهاز Windows أو بحاويات المشروع المحلية أو بشبكة إنتاجية.
- اسم قاعدة الاختبار الأساسية هو `shishli_ci_test` على `127.0.0.1:3306`. يوجد فحص للاسم والبيئة والاتصال قبل الاختبارات. حساب root وكلمة المرور المكتوبة في YAML خاصان بخدمة الاختبار المؤقتة فقط، لتمكين اختبارات إنشاء قواعد MySQL العشوائية؛ ليست أسرارًا إنتاجية.
- فُعّلت مفاتيح `INVENTORY_MYSQL_CONCURRENCY` و`DRIVER_MYSQL_CONCURRENCY` و`BRANCH_MYSQL_TEST` لاختبارات MySQL القائمة. الجلسات والكاش والبريد تستخدم array، والطابور sync، وفق عزل الاختبارات الحالي. يُفحص اتصال Redis أيضًا دون إرسال بريد أو تشغيل worker.
- لا توجد أوامر نشر أو تسجيل دخول إلى registry أو `docker push` أو وصول VPS أو صلاحية packages write. لا حاجة لإضافة أي GitHub Secrets لتشغيل هذا الإعداد.
- لا يستدعي Workflow `migrate:fresh` مباشرة. بعض اختبارات المشروع تستخدم آلية تحديث قاعدة الاختبار التابعة لـLaravel أو تنشئ قواعد مؤقتة خاصة بها؛ هذا يحدث داخل خدمة MySQL المعزولة فقط. لا يُنفّذ إعداد قاعدة إنتاج أو seeder خارج الاختبارات.
- ملفات القفل وإصدارات المكتبات وإعدادات Docker المحلية ومجلد `Şişli` بقيت دون تعديل. لم تُحذف ملفات المشروع أو بياناته أو volumes، ولم تُغيّر حاويات المشاريع الأخرى.

## نتائج الفحص المحلي

| الفحص | النتيجة الفعلية |
| --- | --- |
| `actionlint 1.7.12` | نجح فحص بنية Workflow والتعبيرات. نُزّلت الأداة الرسمية إلى Windows Temp وتحققت بصمتها SHA256؛ فحصا shellcheck وpyflakes غير متاحين محليًا وعُطّلا في هذا الفحص فقط |
| Composer validate | نجح، دون تغيير ملف القفل أو تثبيت إصدارات جديدة |
| Pint على جميع ملفات backend | نجح بعد تصحيح مسافات GeoServiceTest فقط؛ الفحص لا يعطل فشل التنسيق في CI |
| Laravel محليًا | نجحت مجموعة `RestaurantSettingsTest\|GeoServiceTest\|OrderStateMachineTest\|PaymentStateMachineTest`: عددها 182 اختبارًا و460 تحققًا، على SQLite داخل الذاكرة. ثم أُعيدت اختبارات GeoService بعد تنسيق الملف: 5 اختبارات ناجحة |
| مجموعة Laravel الكاملة محليًا | بدأت ولم تُصدر نتيجة نهائية في الفحص المطوّل؛ أُوقفت. لا تُعدّ ناجحة، ولا تُستبدل المجموعة الكاملة بالمجموعة المحددة داخل Workflow |
| Node 22 / Linux | نجح `npm ci` والاختبارات الثلاثة ضمن `test:delivery-location`، واختبارا الهاتف، والبناء الإنتاجي. جرى ذلك بنسخة Linux من Node 22 في حاوية مؤقتة، والمصدر مركّب للقراءة فقط؛ لم يتغير node_modules المحلي أو القفل |
| Compose | نجح `config --quiet` مع profile الاختبارات، بقراءة ملفات env المحلية دون طباعتها أو تعديلها ودون بدء خدمات |
| صور Docker | نجح بناء هدف Laravel الإنتاجي وصورة Vue/Nginx بأسماء فحص مستقلة `shishli-ci-app-check:local` و`shishli-ci-nginx-check:local`؛ استُخدم الكاش في طبقات البناء، ولم تُنشر الصور أو تُشغّل |

محاولة Node الأولى استخدمت tmpfs لم يسمح بتحميل مكتبة Rolldown الأصلية؛ أُعيد الفحص بنظام ملفات الحاوية العادي فنجح، دون تعديل القفل أو المكتبات. بيئة GitHub تستخدم نظام ملفات runner العادي ولا تستخدم ذلك tmpfs.

## ما لا يزال يتطلب GitHub

بعد مراجعة الملفات ورفعها بنفسك إلى المستودع، افتح تبويب **Actions → CI** لمراجعة المهام الثلاث. أول push إلى `master` أو PR يستهدفه سيبدأ الفحص تلقائيًا إذا كانت Actions مفعلة وسياسة المستودع تسمح بالـActions المحددة. طلبات المساهمين الخارجيين قد تحتاج موافقة مسؤول المستودع بحسب إعداداته.

لم يُتحقق محليًا من التشغيل الكامل على GitHub، أو إعدادات السماح بالـActions في المستودع، أو كامل مجموعة Laravel على خدمة MySQL 8.4 مع التزامن. لم يُستخدم MySQL المحلي لاختبار هذا Workflow. أول تشغيل قد يكشف اختلافات Linux أو MySQL أو مشكلات اختبارات سابقة؛ لن يخفيها CI.

لا توجد إعدادات branch protection أُضيفت عن بُعد. بعد أول تشغيل ناجح يمكنك اختيار جعل المهام فحوصًا إلزامية لحماية `master` من إعدادات GitHub؛ هذه خطوة منفصلة لم تُنفّذ.

## الملفات الجديدة والمعدلة

1. جديد: `.github/workflows/ci.yml`.
2. جديد: `docs/CI_SETUP_REPORT.md`.
3. معدل: `back-end/tests/Unit/GeoServiceTest.php` — إضافة مسافات بعد الفواصل في استدعاء واحد فقط لتوافق Pint.

## مراجع الإعداد

- [GitHub: خدمات Docker المعزولة ودورة حياتها](https://docs.github.com/en/actions/tutorials/use-containerized-services/use-docker-service-containers).
- [GitHub: إعداد Redis كخدمة اختبار](https://docs.github.com/en/actions/tutorials/use-containerized-services/create-redis-service-containers).
- [setup-node v4: تحديد Node ومسار قفل التخزين المؤقت](https://github.com/actions/setup-node/tree/v4).
- [setup-php: إعداد PHP والامتدادات وComposer](https://github.com/shivammathur/setup-php).
- [actionlint الرسمي](https://github.com/rhysd/actionlint).
