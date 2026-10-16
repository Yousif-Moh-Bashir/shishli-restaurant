# SHISHLI — PHP Runtime: Phase 1 Baseline & Candidate Assessment

تاريخ التنفيذ: 16 أكتوبر 2026، Africa/Khartoum. الفرع: `feature/ghcr-publishing`. المصدر: `1561d2545a4d64c946c328b82b19ec617f209907`.

**القرار: الإبقاء على النشر محجوبًا. لا يُعتمد أي بديل حاليًا ولا يُعدل Dockerfile الإنتاجي.** Trixie أفضل مرشح لاستكمال التقييم والصيانة، لكنه ما زال يفشل السياسة الحالية. Alpine لا يُعتمد بناءً على صفر Trivy بسبب دليل libxml2 مستقل عن عداد الفحص.

## 1. خط أساس GitHub وحدود الإثبات

المرجع الذي قدمه المستخدم: [Run 38845986381 / Job 116796516552](https://github.com/Yousif-Moh-Bashir/shishli-restaurant/actions/runs/38845986381/job/116796516552)، commit `1561d25`، فشل `Scan PHP image` برمز 1، و77 HIGH و3 CRITICAL من السجل النصي الذي أكده المستخدم.

تحققت صفحة GitHub العامة بصورة مستقلة من اسم الخطوة الفاشلة وexit code 1. وتحققت REST API من head SHA الكامل المطابق للمصدر المحلي، ومن وجود **Artifactين فقط**:

- `Yousif-Moh-Bashir~shishli-restaurant~09H8C0.dockerbuild`، ID `12023830478`.
- `Yousif-Moh-Bashir~shishli-restaurant~KKODP6.dockerbuild`، ID `12023133022`.

الاستجابة الأصلية محفوظة في [github-artifacts.json](security/php-runtime-phase1/github-artifacts.json). لا يوجد Artifact مخصص لـTrivy JSON ضمن القائمة. لم تُنزل محتويات سجلي البناء، ولم تُفترض خلو كل ملفات ZIP الداخلية من JSON. لكن Workflow الحالي لا يطلب إخراج Trivy بصيغة JSON ولا يرفعه؛ سجلا Docker Build ليسا تقرير Trivy قابلًا للمقارنة. صفحة job تطلب تسجيل الدخول لعرض السجلات الكاملة.

| المعلومة | ما ثبت |
| --- | --- |
| وصفة الصورة | `docker/php/Dockerfile`: `php:8.3.35-fpm-bookworm`، target `production`، linux/amd64 |
| اسم الصورة على runner | `shishli-app:verify`، ثم `docker save` إلى `/tmp/app.tar`؛ الفحص يستخدم الأرشيف |
| Trivy في Workflow | مثبت على `v0.69.3`؛ trivy-action `0.35.0` مع `skip-setup-trivy: true` |
| قاعدة الثغرات على runner | **غير مثبتة**؛ لا metadata أو DB hash متاحان من سجل GitHub الذي أمكن الوصول إليه |
| Digest صورة GitHub النهائية أو صورة أساسها أثناء ذلك التشغيل | **غير مثبت**؛ لا يُستنتج من الوسم أو SHA المصدر أو تطابق عدد النتائج |
| نتيجة 77/3 على GitHub | وفق سجل المستخدم؛ صفحة job تؤكد فشل الخطوة فقط، لا تفاصيل عداد الثغرات |

صور JSON أدناه **فحوص محلية جديدة لنفس المصدر**، وليست استخراجًا لتقرير GitHub المفقود. لم أستخدم PAT أو أقرأ أسرارًا أو أعدّل إعداد GitHub.

## 2. منهج المقارنة وقاعدة Trivy

نُسخت قاعدة الدراسة إلى مجلد مستقل، ثم نجح `image --download-db-only` للتحقق من التحديث. القاعدة لم تكن مستحقة للتحديث بعد، فلم يتغير snapshot. جميع عمليات الفحص سبقت NextUpdate.

- Trivy الفعلي محليًا: **0.69.3**. استُخدم الأرشيف الرسمي بعد مطابقة checksum، ثم تحقق `--version`.
- DB schema: **2**.
- UpdatedAt: `2026-10-15T19:09:25.631494278Z`.
- DownloadedAt: `2026-10-15T21:58:45.7543363Z`.
- NextUpdate: `2026-10-16T19:09:25.631493777Z`.
- SHA256 قبل المقارنة وبعدها: `32c6ed2327c56930f4814c2f243bcdbf54fe4b6b42d85533e53d0a02ebe36e4d`.

الأعلام المشتركة: `--scanners vuln,secret --list-all-pkgs --ignore-unfixed=false --severity UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL --format json`. استُخدم `--skip-db-update` فقط بعد التحقق من صلاحية snapshot، لتوحيد قاعدة المقارنة بين الصور؛ ليس تعديلًا لسياسة Workflow.

أُعيد بناء المرشحين بـ`--pull` تحت وسوم جديدة مستقلة، دون نقلها إلى أسماء صور المشروع المستخدمة. استفادت طبقات Runtime من BuildKit cache؛ لا أدعي أن --pull يعيد تنفيذ apt upgrade تلقائيًا. لذلك نُفذ فحص إضافي **دون كاش** لفهارس apt/apk الرسمية للحزم الحرجة، داخل صور تحقق مشتقة، وأكد مطابقة Installed وCandidate للحزم الثلاث. لم تُرقَّ هذه الصور المشتقة فوق المرشحين الممسوحين.

أعيد تطبيق حد السياسة HIGH/CRITICAL على أرشيف Bookworm مع `--exit-code 1`؛ أعاد **1** كما هو متوقع. إنشاء JSON الكامل لا يعني اجتياز gate. لم تتغير أي قاعدة فحص أو قائمة تجاهل.

## 3. هويات الصور المحلية القابلة لإعادة الفحص

| المرشح | وسم محلي جديد | Digest الناتج من metadata البناء |
| --- | --- | --- |
| Bookworm | `shishli-php-phase1:bookworm-20261016` | `sha256:417f8ff0efc02c998c79dbd177195bc4378fa0cca8ab13af849eacd6a0cdfba2` |
| Trixie | `shishli-php-phase1:trixie-20261016` | `sha256:e92494badf1a3ec81293cbeb2bdccd08ea1c93555c20081cf26a409ac045b3b5` |
| Alpine | `shishli-php-phase1:alpine-20261016` | `sha256:d8bdf0fb3ba41753bf8f75f6675f4675fb9714eea4be9e9330573335b019b4fb` |

صورة الأساس Bookworm التي حُلّت في هذا البناء: `php:8.3.35-fpm-bookworm@sha256:f768bd9142444f6126a690697b028c23f30878c3b628a7c355dfce75e29cc045`. هذه هوية أساس **البناء المحلي** ولا تنسب لتشغيل GitHub دون دليله.

هويات الأساس الأخرى وComposer، وimage IDs وbuild manifest digests وhash لكل JSON ووصفة، محفوظة في [comparison.json](security/php-runtime-phase1/comparison.json) وملفات `*-build-metadata.json`. لا تُخلط هوية الصورة النهائية بهوية صورة الأساس أو config ID أو hash ملف tar. الصور لم تُرفع إلى registry.

وصفة Bookworm مطابقة نصيًا للإنتاج الحالي. وصفة Trixie تغير التوزيعة وتصحح فلترة مخرجات dpkg-query لاستبعاد أسطر diversions؛ الاستبدال المباشر لـFROM ليس وصفة مكافئة دون ذلك. Alpine يستخدم apk وscanelf لبناء الامتدادات وربط المكتبات، مع `icu-data-full` وtzdata. هذه وصفات تحقق فقط أعيد استخدامها من الدراسة السابقة وأعيد بناؤها وفحصها على المصدر الحالي، وليست تعديلًا لوصفة الإنتاج.

## 4. النتائج الجديدة

| الصورة | النظام | HIGH | CRITICAL | ثغرات تبعيات التطبيق | أسرار | حزم OS | الحجم |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Bookworm | Debian 12.15 | 77 | 3 | 0 | 0 | 121 | 184,662,292 bytes |
| Trixie | Debian 13.7 | 54 | 1 | 0 | 0 | 119 | 198,216,388 bytes |
| Alpine | Alpine 3.24.2 | 0 | 0 | 0 | 0 | 54 | 63,602,003 bytes |

هذه أحجام Docker المحلية، وليست أحجام تنزيل مضغوطة أو RAM. Composer صفر يعني عدم وجود advisories مبلغ عنها في snapshot، وليس ضمانًا لغياب ثغرات غير معروفة. تغير حجم التطبيق قليلًا عن الدراسة السابقة، ولذلك أثبتت هويات الصور الجديدة بدل إعادة استخدام digests القديمة.

| تصنيف HIGH/CRITICAL بحسب بيانات الموزع | Bookworm | Trixie |
| --- | ---: | ---: |
| affected | 57 | 54 |
| fix_deferred | 22 | 1 |
| will_not_fix | 1 | 0 |
| نتائج تحمل FixedVersion متاحًا لنفس التوزيعة في snapshot | 0 | 0 |

لا يعني FixedVersion الفارغ استحالة التصحيح upstream أو أن كل advisory ينطبق على binary فعليًا. وتصنيفات الموزع ليست قبولًا للمخاطر.

الحزم المتأثرة في Bookworm: مجموعة util-linux بثماني حزم (48 HIGH)، curl/libcurl (10)، SQLite (2 HIGH و1 CRITICAL)، libxml2 (6 HIGH و1 CRITICAL)، OpenSSL بأداته ومكتبته (2 HIGH)، systemd libraries (2)، ncurses/libtinfo (3)، ثم libacl وOpenLDAP وgzip وperl-base بنتيجة HIGH لكل منها، وzlib بنتيجة CRITICAL. بعض CVEs يتكرر عبر binary packages؛ 80 نتيجة Bookworm تمثل **29 CVE و24 حزمة**.

التفصيل لكل CVE/package/installed/fixed/status في [high-critical-findings.csv](security/php-runtime-phase1/high-critical-findings.csv)، ويضم 135 صف نتيجة للمرشحين معًا. الملخص الآلي يسرد المجموعة لكل حزمة وروابط advisories. المصادر الكاملة:

- [bookworm-scan.json](security/php-runtime-phase1/bookworm-scan.json)
- [trixie-scan.json](security/php-runtime-phase1/trixie-scan.json)
- [alpine-scan.json](security/php-runtime-phase1/alpine-scan.json)

## 5. توفر التصحيحات الرسمية للحزم الحرجة

بعد `apt-get update` أو`apk update` دون كاش في صور تحقق منفصلة، كانت الإصدارات المثبتة مساوية للمتاحة التالية:

| المكتبة | Bookworm | Trixie | Alpine |
| --- | --- | --- | --- |
| libxml2 | `2.9.14+dfsg-1.3~deb12u6` | `2.12.7+dfsg+really2.9.14-2.1+deb13u3` | `2.13.9-r2` |
| SQLite | `3.40.1-2+deb12u2` | `3.46.1-7+deb13u2` | `3.53.4-r0` |
| zlib | `1:1.2.13.dfsg-1` | `1:1.3.dfsg+really1.3.1-1+b1` | `1.3.2-r1` |

مخرجات سياسة الحزم الأصلية، بما فيها المستودعات والإصدارات Installed/Candidate، مضمنة داخل comparison.json.

- **SQLite / CVE-2025-7458:** [Debian](https://security-tracker.debian.org/tracker/CVE-2025-7458) يؤكد Bookworm مصابًا وTrixie مصححًا. الترقية إلى Trixie تحسن حقيقي هنا. الاستغلال يحتاج SQL مصممًا؛ استخدام التطبيق MySQL يقلل مسار التعرض الظاهر لكنه لا ينفيه أو يزيل SQLite من PHP.
- **libxml2 / CVE-2026-6653:** [Debian](https://security-tracker.debian.org/tracker/CVE-2026-6653) ما زال يصنف Bookworm وTrixie مصابين. اسم إصدار Trixie يتضمن really2.9.14؛ لا يمثل upstream 2.12.7 مصححًا. له إصلاح upstream، لكن الحزمة المرشحة الحالية لم تعالجه.
- **zlib / CVE-2023-45853:** [Debian](https://security-tracker.debian.org/tracker/CVE-2023-45853) يوضح أن contrib/minizip المصاب لا يُبنى ضمن binary packages المعنية في Bookworm. هذه قرينة قوية لعدم انطباق ذلك التحذير على zlib1g المحددة، لكنها لم تتحول إلى استثناء أو تعديل gate. Trixie يصنف مصححًا؛ لا يجوز حذف zlib نفسها لأنها تبعية فعلية لـPHP ومكتباته.

**لم تظهر ترقية رسمية إضافية للحزم الثلاث داخل مستودعات المرشحين وقت التحقق.** لا يُقترح خلط sid/forky أو حزم توزيع مختلف مع Runtime مستقر.

## 6. لماذا لا يُعتمد صفر Alpine؟

الصورة الجديدة تحمل libxml2 `2.13.9-r2`. [وصفة Alpine الحالية](https://github.com/alpinelinux/aports/blob/3.24-stable/main/libxml2/APKBUILD) تستخدم upstream 2.13.9 وتضيف patch لـCVE-2026-6732، وليس إصلاح dict.c لـCVE-2026-86138. [Debian](https://security-tracker.debian.org/tracker/CVE-2026-86138) يربط overflow في xmlDictAddQString بإصلاح upstream 2.15.4 و[commit الإصلاح](https://github.com/GNOME/libxml2/commit/a4cba4b5b5a8c42e155ed42d2d2a44955465a2e4). تتطابق نسخة الحزمة الجديدة مع النسخة التي وثقت الدراسة السابقة فيها التعبير غير المصحح وغياب guard.

لا يثبت ذلك تنفيذ exploit ناجح ضد Shishli، لكنه دليل مكون غير مصحح لا يظهر في نتيجة Alpine، ويمنع إعلان الأمان من عدد scanner وحده. توجد مقارنة المصدر والدليل التفصيلي في [PHP_RUNTIME_SECURITY_DECISION.md](PHP_RUNTIME_SECURITY_DECISION.md). يبقى فحص backports واستكمال تحليل reachability مطلوبًا، دون إزالة النتيجة أو تعطيل البوابة.

## 7. الاعتمادات والتوافق المنفذ فعلًا

كل المرشحين: PHP **8.3.35**، Redis extension **6.3.0**، Laravel **13.33.0**، وcomposer.lock نفسه دون تعديل. SQLite في runtime: 3.40.1 / 3.46.1 / 3.53.4؛ ICU: 72.1 / 76.1 / 78.1؛ libxml الفعلية: 2.9.14 / 2.9.14 / 2.13.9.

نجحت الفحوص التالية **لكل مرشح** في خطوات BuildKit مع `--network none`، باستخدام [verify.Dockerfile](security/php-runtime-phase1/verify.Dockerfile):

- PHP-FPM config وcgi-fcgi ping يعيد pong؛ OPcache enabled عبر طلب FastCGI فعلي داخل خطوة البناء.
- pdo_mysql وpdo_sqlite وRedis وGD وintl وzip وbcmath وpcntl وOPcache، وكذلك DOM/libxml/OpenSSL/fileinfo/POSIX/ctype.
- إنشاء SQLite في الذاكرة وكتابة/قراءة؛ ZIP إنشاء/فتح/قراءة؛ GD ترميز JPEG/PNG/WebP؛ fork/wait عبر pcntl.
- بيانات العربية في IntlDateFormatter وAsia/Riyadh؛ نجح Alpine مع full ICU ولم يكتفِ بمجرد تحميل intl.
- Laravel artisan version وAPI route:list مع api/v1؛ غياب gcc وGit وComposer من production؛ عدم وجود روابط shared libraries مفقودة في PHP/FPM/الامتدادات.

لم تتصل الفحوص بـMySQL أوRedis ولم تُنفذ migrations على قاعدة محلية. تشغيل FPM هنا عملية اختبار مؤقتة داخل BuildKit، لا تشغيل خدمة المشروع أو أمر docker run. **لم تُعد مجموعة Unit/Feature الكاملة أو اختبارات التزامن في هذه المرحلة**؛ نتائج الدراسة السابقة لا تسجل نجاحًا جديدًا لها. تعداد 121/119/54 حزمة لا يقيس الأمان وحده ولا يثبت reachability.

Trixie يحافظ على glibc/apt ويحتاج تعديل فلتر dpkg المذكور؛ Alpine ينتقل إلى musl/apk ويحتاج اختبار DNS والتوقيت والملكية وامتدادات C والربط. حذف libxml2 غير مقبول كاختصار: composer.lock الإنتاجي يتطلب ext-dom/ext-libxml عبر css-to-inline-styles. SQLite مدمج أصلًا في بناء PHP الرسمي؛ حذف سطر pdo_sqlite وحده لا يزيل المكتبة. أدوات البناء منفصلة أصلًا؛ تقليل Runtime الإضافي يحتاج تحليل اعتماد لا حذف ملفات system packages أو بيانات scanner.

الصور الثلاث رسمية ومتاحة في [قائمة PHP الرسمية](https://github.com/docker-library/official-images/blob/master/library/php). Trixie يدعم glibc مع [دورة دعم أطول](https://www.debian.org/releases/trixie/)؛ [Bookworm في LTS](https://www.debian.org/releases/bookworm/). دعم أمان [PHP 8.3](https://www.php.net/supported-versions.php) ينتهي بنهاية 2027، مهما كانت التوزيعة. أزمنة البناء محفوظة في comparison.json، لكنها متفاوتة الكاش وليست benchmark صالحًا للمقارنة.

## 8. إعادة التجربة وحدودها

الوصفات الثلاث وmetadata البناء وJSON الفحص محفوظة مع هذا التقرير. ملفات الصور والسجلات وDB snapshot وأدلة التشغيل التفصيلية موجودة في `%TEMP%\shishli-php-phase1-20261016`؛ قد ينظف Windows Temp لاحقًا. لم تُنسخ قاعدة Trivy الكبيرة إلى المستودع؛ يحتفظ الملخص بهويتها ووقت صلاحيتها.

مثال إعادة بناء/فحص مرشح من جذر المستودع، مع ملفاته خارج الإنتاج:

```powershell
$phaseDir = Join-Path $env:TEMP 'shishli-php-phase1-20261016'
docker build --pull --platform linux/amd64 --target production --metadata-file "$phaseDir/trixie-build-metadata.json" -f docs/security/php-runtime-phase1/trixie.Dockerfile -t shishli-php-phase1:trixie-20261016 .
docker save -o "$phaseDir/trixie.tar" shishli-php-phase1:trixie-20261016
& "$phaseDir/trivy-tool/trivy.exe" image --cache-dir "$phaseDir/cache" --skip-db-update --input "$phaseDir/trixie.tar" --scanners vuln,secret --list-all-pkgs --ignore-unfixed=false --severity UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL --format json --output "$phaseDir/trixie-scan.json"
```

قبل أي إعادة مستقبلية حدّث قاعدة الفحص عند الاستحقاق وافحص الجميع بالنسخة الجديدة؛ لا تستخدم snapshot قديمًا لمجرد مطابقة الأعداد. لا تدعي أن إعادة البناء ستنتج digest نفسه: وسوم الأساس/Composer وPECL والحزم الخارجية قابلة للتحديث. تثبيت digest/إصدارات أدوات البناء جزء من الخطوة التالية بعد اختيار صورة قابلة للصيانة.

## 9. التوصية والخطوة التالية قبل تعديل الإنتاج

1. اعتماد JSON المحلي الجديد كخط أساس **للمقارنة المحلية** مع توثيق الفرق عن GitHub؛ للحصول على تطابق كامل مع run المحدد يلزم سجل Trivy الفعلي وmetadata الصورة/سجلي البناء وDB snapshot أو metadata منه. لا يوجد Trivy JSON artifact مخصص في ذلك التشغيل.
2. إبقاء PHP Bookworm محجوبًا دون استثناءات. لا توجد حزمة رسمية مرشحة أحدث للمكونات الحرجة في المستودعات المفحوصة.
3. متابعة إصلاحات libxml2 الرسمية؛ Trixie المرشح الأول للصيانة بعد زوال blockers، وليس تغييرًا جاهزًا للدمج الآن. Alpine لا يعتمد قبل حسم فجوة libxml2 واختبارات musl والملكية.
4. إذا أريد تسريع المعالجة ببناء مكتبات core مخصصة، فهذا مسار مستقل يحتاج موافقة وتقييم صيانة وترقيع؛ لم يُنفذ هنا.
5. بعد توفر مرشح مصحح، اختبر **الصورة نفسها** ضد MySQL 8.4 وRedis 7.4 المعزولين في CI، وكامل Feature/concurrency وQueue/Scheduler وNginx/FastCGI وDNS وmedia وrollback. PHP المثبت على runner ليس بديلًا عن اختبار صورة Runtime.
6. لا تعديل Dockerfile الإنتاجي ولا اعتماد صورة حتى مراجعة هذه النتائج والموافقة على المرحلة التالية، مع بقاء gate الحالي HIGH/CRITICAL دون تخفيف.

## 10. نطاق التغييرات

نجح فحص اتساق JSON/CSV والأعداد وhash تقارير الفحص، والتحقق من 135 صف HIGH/CRITICAL، ومطابقة SHA المصدر. كما طابقت SHA256 لجميع ملفات الإنتاج وCompose وWorkflows وملفات القفل و.dockerignore مع بصمتها قبل التنفيذ؛ لم يتغير أي منها. فحص `git diff --check` نجح؛ الملفات الجديدة فقط هي هذا التقرير وأدلته الأربعة عشر.

أضيف هذا التقرير ومجلد أدلته فقط. لم يُعدل Compose أو Dockerfiles الإنتاجية أو GitHub Actions أو ملفات القفل أو Trivy gate أو secrets أو Branch Protection. لم تُشغَّل/توقف/تحذف حاويات أو Volumes أو قواعد بيانات؛ اقتصرت عمليات Docker على قراءة بيانات الصور والبناء التجريبي والتصدير. صور المشروع الموجودة ووسومها لم تُستبدل، ولم تُحذف صور أو كاشات. لا commit أو push أو merge.
