# SHISHLI — Phase 3B: GitHub Container Registry

## التصحيح المثبت لتوافق Compose v2.38.2 — PR #2

هذا القسم يصحح استنتاج الإصلاح السابق أدناه: نجاح `v5.1.4` وظهور الخيار في help **لا يثبتان** توافق `v2.38.2`. السبب ليس فحصًا ثانيًا أو غياب الخيار؛ أُعيد إنتاج الفشل بالأمر الذي يحتويه فعلًا.

### السبب الجذري والأمر الفاشل

تمت مراجعة `.github/workflows/ghcr.yml` كاملًا. قبل هذا التصحيح كان استدعاء Compose محصورًا في خطوة `Validate deployment Compose without runtime secrets` داخل job `build`: `version` ثم `config --help` ثم الأمر الفاشل:

```sh
docker compose -f compose.ghcr.yaml config --quiet --no-env-resolution
```

لا يوجد فحص Compose ثانٍ في خطوات بناء الصور أو فحص الطبقات أو Trivy أو gate أو publish. يأتي هذا الفحص بعد checkout وsetup-buildx وقبل اختبارات gate والبناء. أوامر Compose في `.github/workflows/ci.yml` تخص Compose المحلي وليست قارئًا لـ`compose.ghcr.yaml`؛ لم تتغير هنا.

تأكد دعم الاسم `--no-env-resolution` في **help للأداة v2.38.2 نفسها**، لكنه غير كافٍ: [مصدر config.go لذلك الإصدار](https://github.com/docker/compose/blob/v2.38.2/cmd/compose/config.go#L67) يمرر `WithDiscardEnvFile` دون `WithoutEnvironmentResolution` أثناء تحميل المشروع. فحص `noResolveEnv` يأتي لاحقًا داخل `runConfigInterpolate`، بعد `ToProject`. مرحلة `LoadProject` في [compose.go](https://github.com/docker/compose/blob/v2.38.2/cmd/compose/compose.go#L276) تقرأ ملفات الخدمة المطلوبة قبل بلوغ ذلك الفحص. لذلك يفشل الأمر عند ملف app.env الغائب حتى مع الخيار.

الدليل التنفيذي: تنزيل إصدار Windows الرسمي من [release v2.38.2](https://github.com/docker/compose/releases/tag/v2.38.2) إلى Temp ومطابقة SHA256 مع checksums.txt الرسمي: `ba8f09d3873f7a9755b863ed2013a1276b96fcbbc074c69ff3d3cfbce3e0186f`. أظهر `version` الإصدار المطلوب؛ الأمر السابق أعاد exit 1 وخطأ app.env الغائب. على Windows يظهر المسار مترجمًا داخل مسار المشروع، مقابل `/etc/shishli/app.env` على Ubuntu. هذا يثبت فشل الأمر والتوافق، وليس مجرد تخمين من اسم الخطوة. سجل GitHub الكامل وSHA التشغيل لم يُجلبا عبر اتصال مصادق؛ تحديد الخطوة يستند إلى مراجعة Workflow الحالي والمخرجات التي قدمها المستخدم وإعادة الإنتاج.

### التعديل

استبدلت الخطوة أوامرها باستدعاء:

```sh
python3 .github/scripts/test_validate_ghcr_compose.py
python3 .github/scripts/validate-ghcr-compose.py
```

أداة التحقق تستخدم Python stdlib فقط. تنشئ `TemporaryDirectory` خارج المستودع بثلاثة ملفات: app.env بقيم APP_ENV=testing وAPP_DEBUG=false، وmysql.env باسم قاعدة اختبار غير سرية، وملف فارغ خاص باستبدال متغيرات Compose. لا توجد مفاتيح أو كلمات مرور للنشر. تُحدد مسارات ملفي الخدمة عبر المتغيرين الموجودين أصلًا `SHISHLI_RUNTIME_ENV_FILE` و`SHISHLI_MYSQL_ENV_FILE`، وتثبت منفذ التحقق 8089 ووسم الصورة الممرر من Workflow. تُحذف متغيرات COMPOSE_* الموروثة وتُمرر `--env-file` صراحة لمنع تحميل `.env` للمطور أو ملفات Compose بديلة. الأمر الفعلي الوحيد لفحص النموذج هو:

```sh
docker compose --env-file <temp>/interpolation.env -f <repo>/compose.ghcr.yaml config --quiet
```

الملفات مؤقتة ومعزولة وتُحذف عند النجاح والفشل. لا تُطبع محتوياتها أو النموذج المحلول، ولا تُستخدم أسرار Staging/Production. `config` لا يشغل حاويات ولا يحتاج daemon أو قواعد بيانات. بقيت مسارات `/etc/shishli/*` الافتراضية في `compose.ghcr.yaml` دون تعديل؛ التوجيه إلى Temp خاص بعملية التحقق فقط.

### الاختبارات وحدود التحقق

| التحقق | النتيجة |
| --- | --- |
| v2.38.2 الرسمي: help + الأمر القديم بالخيار | الخيار مدعوم بالاسم؛ الأمر يفشل exit 1 عند app.env كما أبلغ GitHub |
| v2.38.2 الرسمي: الفحص البديل | exit 0 |
| اختبارات regression على v2.38.2 | 3 ناجحة: النموذج الحقيقي مع مسارات موروثة غير موجودة وCOMPOSE_FILE/COMPOSE_ENV_FILES غير صالحين؛ رفض GHCR_IMAGE_TAG فارغ؛ رفض depends_on لخدمة مفقودة. جميعها تتحقق من حذف الملفات المؤقتة، والأول يثبت عدم تعديل ملف النشر |
| الفحص والاختبارات الثلاثة على v5.1.4 | ناجحة أيضًا |
| actionlint لـghcr.yml | ناجح؛ shellcheck وpyflakes غير متاحين محليًا |
| git diff --check | ناجح |

تشغيل اختبارات التوافق محليًا: اضبط `SHISHLI_TEST_COMPOSE_BINARY` إلى مسار الأداة المستقلة v2.38.2 ثم شغل ملف الاختبارات؛ وللتحقق التنفيذي استخدم `validate-ghcr-compose.py --compose-binary <path>` مع GHCR_IMAGE_TAG. داخل Actions تستخدم الاختبارات الأداة المثبتة على Ubuntu مباشرة قبل البناء، لذا فشل التوافق يمنع التقدم. التحقق المحلي نفذ **الإصدار نفسه على Windows**، وليس Ubuntu؛ إعادة تشغيل PR #2 ما زالت مطلوبة لإثبات النتيجة على runner الفعلي. لم يُثبت أي plugin محل Compose المحلي ولم تُشغل خدمات.

ملفات هذه المعالجة فقط:

- `.github/workflows/ghcr.yml`
- `.github/scripts/validate-ghcr-compose.py`
- `.github/scripts/test_validate_ghcr_compose.py`
- `docs/GHCR_SETUP_REPORT.md`

لم تتغير Trivy أو بوابة CI أو gate/publish أو صلاحيات GHCR أو Compose الإنتاجي. لا `compose up/down/run` أو تعديل حاويات أو قواعد بيانات أو Volumes أو commit أو push. هذه المعالجة تصلح فحص Compose فقط؛ العائق الأمني المستقل يبقى ساريًا.

## إصلاح فحص Compose في Pull Request — 16 أكتوبر 2026

**سجل تاريخي: محدودية هذا الإصلاح مثبتة في قسم v2.38.2 أعلاه، وقد استُبدلت آليته.**

سبب الخطأ: `docker compose -f compose.ghcr.yaml config --quiet` يحاول تحميل ملفات `env_file` للخدمات، ومنها `/etc/shishli/app.env` و`/etc/shishli/mysql.env`. الخيار `--quiet` يمنع طباعة النموذج فقط؛ لا يمنع قراءة ملفات البيئة. الأمر متعلق بفحص Compose داخل build وليس بأمر بناء الصور أو تسجيل دخول GHCR.

عند هذه المراجعة كانت النسخة المحلية تحتوي **بالفعل** على `--no-env-resolution` في آخر خطوة `Verify image layers, paths, routes and Vue assets`، ولذلك لا يمكن نسبة الفشل المبلغ عنه إلى هذه النسخة دون مراجعة SHA وسجل التشغيل الفاشل على GitHub. أُعيد إنتاج نفس نوع الخطأ محليًا بحذف الخيار: خروج 1 لغياب app.env؛ ترجمة المسار على Windows تختلف عن مسار Linux المبلغ عنه.

التعديل في `.github/workflows/ghcr.yml` ينقل الفحص إلى خطوة مستقلة مبكرة `Validate deployment Compose without runtime secrets` قبل بناء الصور، مع تسجيل نسخة Compose واشتراط ظهور الخيار في `config --help`، ثم تشغيل:

```sh
docker compose -f compose.ghcr.yaml config --quiet --no-env-resolution
```

يُمرر `GHCR_IMAGE_TAG=sha-<commit>` عبر بيئة الخطوة فقط، وليس ملف env. الخيار يمنع تحميل **ملفات بيئة الخدمات** مع استمرار فحص النموذج واستبدال متغيرات Compose؛ لا يتحقق من صحة محتوى أسرار التشغيل. هذا سلوك موثق في [مرجع Docker الرسمي](https://docs.docker.com/reference/cli/docker/compose/config/). لا fallback إلى فحص يقرأ الأسرار إذا كانت النسخة قديمة؛ تفشل خطوة التوافق بوضوح ويحتاج runner تحديث Compose.

نتائج التحقق الفعلية:

- Compose المحلي `v5.1.4`: الخيار موجود في help؛ الفحص بالصيغة أعلاه نجح بخروج 0 رغم غياب ملفات runtime، والفحص بدونه فشل بخروج 1 كما هو متوقع.
- `actionlint` لـGHCR نجح؛ shellcheck/pyflakes غير متاحين محليًا. `git diff --check` نجح.
- Docker طبع تحذيرًا بأن sandbox يمنع قراءة إعداد المستخدم `.docker/config.json`؛ لم يمنع فحص Compose ولم تُقرأ أو تُطبع بيانات ذلك الملف.
- لم يُنفذ `up` أو `run` أو `start` أو build ضمن هذه المعالجة؛ جميع أوامر Docker المنفذة محليًا كانت version/help/config فقط، ولا تشغل خدمات أو تتصل بقواعد البيانات.
- لم تُنشأ ملفات env وهمية أو أسرار، ولم يتغير `compose.ghcr.yaml` أو Trivy أو gate أو publish أو صلاحيات GITHUB_TOKEN. ملفا هذه المعالجة فقط: Workflow وهذا التقرير. لا commit أو push.

التحقق على نسخة GitHub الفعلية ينتظر إعادة تشغيل Workflow للـSHA الذي يتضمن هذا التعديل؛ الخطوة الجديدة تسجل إصدارها وتثبت دعم الخيار على runner نفسه. لم يُعَد تشغيل GitHub Actions من هذه الجلسة. بقاء عائق Trivy الأمني مستقل عن هذا الإصلاح ولا يُتجاوز.

تاريخ آخر تحديث: 16 أكتوبر 2026، بتوقيت Africa/Khartoum.

**نتيجة Security Remediation الأحدث: نشر GHCR ما زال محجوبًا بسبب PHP.** تمت إعادة البناء بـ`--pull` وفحص OS ومكتبات التطبيق والأسرار، دون تجاوز بوابة الأمان.

| الصورة | HIGH قبل → بعد | CRITICAL قبل → بعد | أسرار بعد |
| --- | --- | --- | --- |
| PHP-FPM | 284 → 77 | 6 → 3 | 0 |
| Nginx/Vue | 60 → 0 | 2 → 0 | 0 |

في PHP أزيلت 210 نتائج بإزالة الحزم غير المطلوبة من Runtime؛ ليست ترقيعات للحزم التي بقيت. في Nginx اختفت النتائج الـ62 بعد ترقية إصدارات الحزم فعليًا. تفاصيل المعالجة وتقييم المتبقي في قسم **Security Remediation — 16 أكتوبر 2026** في نهاية التقرير، والتصنيف الكامل لكل نتيجة في [GHCR_SECURITY_FINDINGS.csv](GHCR_SECURITY_FINDINGS.csv)، وعدد صفوفه 352.

الأقسام التالية حتى قسم Security Remediation تحفظ سجل الإعداد الأولي **قبل المعالجة**؛ أعدادها وصور أساسها التاريخية لا تحل محل النتائج الأحدث أعلاه.

## نتيجة الإعداد الأولي قبل المعالجة

تم إعداد Workflow مستقل للبناء والفحص والنشر إلى GHCR، وCompose مستقل للنشر المستقبلي. نجح بناء صورتي linux/amd64 وفحص الملفات والمسارات والأصول وإعداد Nginx وCompose. لم توجد نتائج أسرار في الصورتين.

**النشر محجوب حاليًا بالسياسة الأمنية المقصودة:** كشف Trivy ثغرات HIGH وCRITICAL في صور الأساس الحالية. نجاح البناء لا يعني نجاح الفحص الأمني. لا توجد استثناءات أو `continue-on-error` لتجاوزها. يلزم معالجة صور الأساس وإعادة الفحص قبل نجاح الرفع الفعلي؛ لم تُغير تبعيات التطبيق أو ملفات القفل لهذا الغرض.

لم تُرفع صور، ولم يُنفذ git add أو commit أو push أو SSH أو نشر VPS. لم تُشغل أو تُوقف أو تُعد إنشاء أي حاوية محلية، ولم تُغير قواعد البيانات أو Volumes. اقتصرت عمليات Docker المحلية على بناء صور تحقق منفصلة، وتصديرها إلى Temp، وقراءة إعدادات وحالة Docker. فحوص Laravel وNginx التنفيذية المحلية جرت في خطوات BuildKit دون شبكة، وليست عبر تشغيل حاويات الخدمات. لم يُعدل compose.yaml أو CI الحالي أو Dockerfiles أو مجلد Şişli أو مشاريع emdadat وhospital.

## الملفات

| الملف | الحالة | الغرض |
| --- | --- | --- |
| `.dockerignore` | معدل | قائمة سماح لسياق البناء واستبعاد البيئة والأسرار والنسخ الاحتياطية ومجلدات أدوات العمل |
| `.github/workflows/ghcr.yml` | جديد | بناء وفحص PR، والنشر بعد CI فقط |
| `.github/scripts/ghcr-gate.mjs` | جديد | التحقق من هوية CI وSHA وmaster وآخر تشغيل ومحاولة ونجاح المهام |
| `.github/scripts/ghcr-gate.test.mjs` | جديد | 14 اختبارًا لبوابة النشر باستخدام استجابات API محاكية |
| `docker/ghcr/inspect-images.py` | جديد | فحص طبقات الصور والملفات والبيئة والمسارات وأصول Vue |
| `docker/ghcr/test_inspect_images.py` | جديد | خمسة اختبارات لفاحص الطبقات، تشمل ملف env المحذوف بطبقة لاحقة |
| `compose.ghcr.yaml` | جديد | قالب مستقل يستخدم صور GHCR دون build |
| `docs/GHCR_SETUP_REPORT.md` | جديد | هذا التقرير |

## ما تم فحصه

- Workflow الحالي `.github/workflows/ci.yml` اسمه `CI`، ويعمل على push إلى master وPR يستهدفه. مهامه: Laravel / PHP 8.3 / MySQL 8.4، وVue / Node 22، وDocker Compose / production builds.
- صورة PHP من `docker/php/Dockerfile`، target `production`، باستخدام PHP 8.3 وComposer دون dev dependencies.
- صورة Nginx/Vue من `docker/nginx/Dockerfile`، ببناء Node 22 وإعداد `VITE_API_BASE_URL=/api/v1`، ثم نسخ dist فقط إلى Nginx.
- إعداد Nginx يمرر `/api/` و`/up` إلى `app:9000` مع `/var/www/html/public/index.php`، ويخدم `/assets/` وأصول SPA مع fallback إلى index.html، و`/storage/` من volume المشترك.
- Compose المحلي يستخدم صورًا محلية وخدمات MySQL وRedis وqueue وscheduler. بقي الملف دون تعديل.
- GitHub CLI غير مثبت، ولا توجد جلسة GitHub مصادق عليها متاحة لهذا العمل؛ إعدادات حساب GHCR وBranch Protection لم تُفحص مباشرة. حماية master ونجاح CI السابق من معطيات المستخدم، وليسا نتيجة فحص حساب مباشر في هذه المرحلة.

## آلية التشغيل

### Pull Request

1. حدث `pull_request` إلى master يشغل job `build` فقط؛ `gate` و`publish` يتخطيان.
2. يبنى commit الاختبار الخاص بالـPR دون login أو push. كلا إجراءي البناء يحددان `push: false`.
3. تعمل اختبارات بوابة النشر وفاحص الطبقات، وفحص الصور والمسارات وأصول Vue وCompose وNginx، ثم Trivy.
4. لا تُرفع ملفات صور Docker كـartifacts في PR، ولا توجد صلاحية `packages: write` في job البناء.

### بعد الدمج إلى master

1. يشغل push تشغيل `CI` الحالي. Workflow GHCR لا يستجيب مباشرة إلى push ولا يمتلك workflow_dispatch لتجاوز CI.
2. حدث `workflow_run` باسم `CI` وبنوع completed على master يستدعي بوابة التحقق؛ شرط الوظيفة الأول يستبعد CI الفاشل وCI الخاص بالـPR أو fork.
3. البوابة تستعلم من GitHub API عن التشغيل الفعلي وتتحقق من workflow ID الخاص بـ`ci.yml`، والمسار، وأن event هو push والفرع master والمستودع هو المستودع نفسه، وأن الحالة completed والنتيجة success.
4. يجب أن يكون SHA الخاص بالتشغيل مطابقًا لحدث workflow_run ولرأس master الحالي. لا يُستخدم github.sha كبديل للـSHA المصرح به في مسار النشر.
5. يجب أن تطابق run_attempt محاولة الحدث، وأن يكون هذا أحدث تشغيل CI بنوع push لهذا SHA على master، وأن تنجح المهام الثلاث المعروفة بأسمائها كاملة. فشل API يرفض النشر.
6. يبنى SHA الناتج من البوابة صراحةً عبر Checkout. تضاف OCI labels للمستودع وrevision، ويُفحص linux/amd64.
7. بعد نجاح **كل** فحوص الصورتين تُرفع ملفات docker save كـartifact داخل تشغيل GHCR نفسه، لمدة يوم واحد. لا يؤخذ كود أو artifact من تشغيل CI سابق أو من PR.
8. job `publish` يعتمد على نجاح gate وbuild، وينزل artifact من التشغيل نفسه، ويحمل الصور نفسها دون إعادة بنائها، ويعيد التحقق من CI وmaster قبل النشر، ويتحقق من revision والمنصة للصور المحملة.
9. يفحص visibility للحزم القائمة، ثم يسجل الدخول بواسطة GITHUB_TOKEN وpassword-stdin، ويرفع وسم SHA الكامل لكل صورة، ثم يعيد التحقق من visibility ويخرج من registry.

إذا تقدم master أثناء البناء، يرفض التحقق الأخير نشر التشغيل القديم. عمليات GitHub API ورفع الصورتين ليست معاملة ذرية: قد يتقدم master بعد آخر تحقق، أو ينجح رفع صورة ثم يفشل رفع الثانية. الوسوم تتضمن SHA الكامل دائمًا ولا يوجد latest، فلا تُنسب صورة إلى commit آخر. لا تستخدم Compose حتى يظهر نجاح النشر للصورتين. إعادة المحاولة لنفس SHA قد تعيد كتابة الوسم؛ وسوم SHA ليست immutable tags يفرضها GHCR. استخدم digest الظاهر في سجلات docker push للتثبيت الأقوى مستقبلًا.

**بدء التشغيل لأول مرة:** يجب دمج ملف workflow إلى الفرع الافتراضي master قبل أن يعمل workflow_run. بعد الدمج انتظر CI الخاص بـmerge commit على master، وليس نجاح CI الخاص بالـPR. إن لم يبدأ GHCR لأول دمج، أعد تشغيل CI الخاص برأس master الحالي من GitHub بعد التأكد من وجود ghcr.yml على الفرع الافتراضي.

## الصلاحيات والاعتماديات

| النطاق | صلاحيات GITHUB_TOKEN |
| --- | --- |
| الافتراضي وbuild | contents: read |
| gate | contents: read، actions: read |
| publish فقط | contents: read، actions: read، packages: write |

- لا PAT ولا SSH ولا أسرار إنتاج ولا Docker build secrets أو build args تحمل إعدادات تشغيل.
- Checkout يحدد persist-credentials: false. تسجيل الدخول يقع بعد انتهاء البناء والفحص وفي job منفصل؛ لا يصل token نشر الحزم إلى خطوات npm أو Composer أو الفاحص.
- Actions مثبتة على SHA كامل؛ تم جلب المراجع من المستودعات الرسمية. Trivy مثبت على v0.69.3، وإجراء الفحص على 0.35.0 SHA `57a97c7...`، وإجراء الإعداد مثبت منفصلًا على v0.2.6 SHA `3fb12ec...`، مع skip-setup-trivy لمنع استخدام إجراء الإعداد المتضمن الأقدم. هذه مراجع موثقة آمنة في إعلان Aqua الرسمي عن حادث مارس 2026، وليست مراجع latest متحركة.
- Buildx مع BuildKit GHA cache لكل صورة، mode=max. كاش PR محدود بنطاق GitHub الخاص بالـPR؛ لا تعتمد عملية نشر workflow_run على artifacts من PR. سياق البناء نفسه يستبعد ملفات التشغيل والأسرار قبل الإرسال إلى BuildKit.
- صور artifact تتبع صلاحيات المستودع، وليست visibility الخاصة بـGHCR. مدة الاحتفاظ يوم واحد. BuildKit cache يحتفظ بطبقات البناء؛ فحص النسخة النهائية لا يغني عن منع الأسرار في سياق البناء.

## الصور والوسوم المتوقعة

```text
ghcr.io/yousif-moh-bashir/shishli-app:sha-<FULL_40_CHARACTER_COMMIT_SHA>
ghcr.io/yousif-moh-bashir/shishli-nginx:sha-<FULL_40_CHARACTER_COMMIT_SHA>
```

المنصة linux/amd64 فقط. app يستخدم target production، وqueue وscheduler يستخدمان نفس صورة app. لا يوجد وسم latest أو وسم فرع متحرك. أسماء GHCR صغيرة الأحرف.

## حماية الملفات والفحص الأمني

- قائمة السماح في .dockerignore تشمل مصادر Laravel المطلوبة والاختبارات اللازمة لاستمرار target testing، ومصادر Vue وملفات Docker اللازمة. تستبعد الملفات غير المطلوبة في جذر backend/frontend بما فيها worktrees المحلية.
- تستبعد `.env*` وauth.json و.npmrc و.netrc ومجلدات .git و.kilo و.codex و.agents وstorage المحلي وconfig cache وvendor/node_modules المحليين وملفات SQL والنسخ الاحتياطية والمفاتيح وملفات PEM/PFX/P12. لا تُرسل الشهادات المحلية أو مجلد Şişli للبناء.
- فاحص docker save يقرأ **كل طبقة** في مسارات التطبيق، بما فيها ملفات أُزيلت لاحقًا، دون استخراج tar إلى القرص. يفحص أسماء الملفات الممنوعة ومحتوى private keys ومتغيرات البيئة الحساسة في config الصورة. وجود شهادات CA عامة في نظام التشغيل طبيعي ولا يعادل تسرب مفاتيح محلية.
- يتحقق من artisan وpublic/index.php وvendor/autoload.php وroutes/api.php، ومن index.html ووجود assets المشار إليها فيه، ووجود `/api/v1` في bundle وإعدادات FastCGI وSPA.
- Trivy يفحص ثغرات OS ومكتبات التطبيق ويمنع HIGH وCRITICAL، **بما فيها ما لا يتوفر له إصلاح** (`ignore-unfixed: false`). يعمل فحص مستقل للأسرار بجميع درجات الخطورة. لا يوجد ملف تجاهل مضاف أو سياسة لتجاوز النتائج.
- هذه فحوص آلية وليست برهانًا مطلقًا لغياب كل سر محتمل. لم تُختبر استجابات API التي تحتاج قاعدة بيانات، أو رحلة مستخدم كاملة عبر HTTP، لأن هذه المرحلة لا تشغل خدمات جديدة ولا تتصل ببيانات محلية.

## نتائج التحقق المحلي الفعلية

| الفحص | النتيجة |
| --- | --- |
| بناء PHP production / linux/amd64 | نجح: `shishli-ghcr-app:verify` |
| بناء Nginx/Vue / linux/amd64 | نجح: `shishli-ghcr-nginx:verify`؛ استفاد من BuildKit cache |
| Compose المحلي مع profile test | نجح config --quiet، دون تشغيل |
| Compose GHCR مستقل | نجح config --quiet --no-env-resolution بوسم SHA اختباري؛ لا حاجة لقراءة أسرار runtime |
| actionlint 1.7.12 للـCI وGHCR | نجح؛ shellcheck وpyflakes غير متاحين محليًا وعطلا لهذا الفحص فقط |
| بوابة CI | 14/14 اختبار Node نجح؛ API محاكى وليس تشغيل GitHub حقيقيًا |
| فاحص الطبقات | 5/5 اختبارات Python نجحت |
| فحص طبقات الصورتين النهائيتين | نجح؛ لا ملفات env أو مفاتيح خاصة أو نسخ SQL/backup محظورة في مسارات التطبيق |
| Laravel API route:list | نجح داخل BuildKit دون شبكة، وتحقق وجود api/v1، دون DB |
| Nginx nginx -t | نجح داخل BuildKit دون شبكة، مع app=127.0.0.1 لأغراض فحص syntax فقط |
| Vue assets وAPI base وSPA config | نجحت الفحوص من محتوى الصورة |
| Trivy secret، كل درجات الخطورة | صفر نتائج في كلتا الصورتين |
| Trivy PHP HIGH/CRITICAL | **فشل مانع للنشر: 284 HIGH و6 CRITICAL، كلها نتائج OS Debian؛ لا FixedVersion لهذه النتائج في قاعدة الفحص المستخدمة** |
| Trivy Nginx HIGH/CRITICAL | **فشل مانع للنشر: 60 HIGH و2 CRITICAL، 62 نتيجة لها FixedVersion** |
| GHCR login/push وvisibility الفعلية | لم تنفذ؛ تحتاج تشغيل GitHub مصادقًا عليه بعد الدمج ومعالجة الثغرات |

الأعداد نتائج package/advisory وليست بالضرورة CVE فريدة أو دليل قابلية استغلال في إعداد التطبيق. أمثلة PHP الحرجة: CVE-2025-7458 في libsqlite3 وCVE-2026-6653 في libxml2 وCVE-2026-43185 في linux-libc-dev وCVE-2023-45853 في zlib. أمثلة Nginx الحرجة: CVE-2026-31789 في libcrypto3 وlibssl3، installed 3.5.5-r0 وfixed 3.5.6-r0. معالجة هذه النتائج تحتاج مراجعة تحديث صور الأساس وحزم النظام وتقليل حزم التطوير في runtime وتقييم نتائج Debian المؤجلة/غير القابلة للإصلاح؛ لم تُقبل استثناءات تلقائيًا.

نُزل Trivy Windows الرسمي إلى Temp وتحقق SHA256 بمقارنته بملف checksums الرسمي. ملفات صور التحقق وتقارير JSON بقيت في Temp بأسماء `shishli-ghcr-app.tar` و`shishli-ghcr-nginx.tar` و`shishli-ghcr-*-scan.json` و`shishli-ghcr-*-secrets.json`؛ لم تُضف إلى Git. أول فحص كشف .kilo worktree محليًا ضمن صورة البناء الأولية، فصُححت .dockerignore وأعيد بناء PHP وفحص الصورتين بنجاح؛ الصورة الأولية لم تُرفع.

أوامر أساسية لإعادة التحقق:

```powershell
node --test .github/scripts/ghcr-gate.test.mjs
python docker/ghcr/test_inspect_images.py
docker build --platform linux/amd64 --target production -f docker/php/Dockerfile -t shishli-ghcr-app:verify .
docker build --platform linux/amd64 -f docker/nginx/Dockerfile -t shishli-ghcr-nginx:verify .
$env:GHCR_IMAGE_TAG = 'sha-0000000000000000000000000000000000000000'
docker compose -f compose.ghcr.yaml config --quiet --no-env-resolution
docker compose -p shishli --profile test config --quiet
```

إذا لم يكن python على PATH، استُخدم محليًا `C:/Users/Yousuf Muhmmed TK/.local/bin/python3.14.exe` الموجود مسبقًا. GitHub Ubuntu يوفر python3.

## الوصول إلى GHCR وPrivate

GitHub ينشئ حزم Container الجديدة Private افتراضيًا؛ OCI source label يربط الصور بالمستودع. لا يوجد خيار visibility في docker push، ولا يغير Workflow visibility تلقائيًا. يفحص قبل الرفع أي package موجودة عبر API للمستخدم Yousif-Moh-Bashir، ويرفض public/internal. 404 يسمح بمحاولة إنشاء package جديدة؛ إذا كانت package موجودة وغير متاحة للتوكن، يفشل الرفع بدل تجاوز صلاحياتها. بعد الرفع يعيد فحص Private.

لم يمكن تأكيد الحالة الفعلية للحساب دون جلسة مصادق عليها، ولم تُغير أي إعدادات GitHub. بعد أول رفع ناجح، افتح Packages في حساب المالك، ثم كل package → Package settings:

1. تأكد من Visibility = Private، ومن ارتباط المستودع Yousif-Moh-Bashir/shishli-restaurant.
2. في Manage Actions access تأكد من أن المستودع نفسه يملك Write. الحزمة المنشأة أول مرة عبر Workflow ترتبط بالمستودع؛ الحزم المنشأة يدويًا سابقًا قد تحتاج منح الوصول يدويًا.
3. لا تمنح مستودعات غير لازمة الوصول. إذا منع الحساب إنشاء الحزم، راجع سياسة Packages مع المالك.
4. لا حاجة لإضافة PAT إلى GitHub Secrets؛ النشر يستخدم GITHUB_TOKEN فقط. سحب الصور الخاصة على VPS مستقبلي غير معد هنا، ويحتاج قرار مصادقة مستقلًا قبل أي نشر؛ هذا الملف لا يضع token على جهاز خارجي.

## Compose للنشر المستقبلي

`compose.ghcr.yaml` ملف standalone، وليس override. لا تدمجه مع compose.yaml. اسم المشروع الافتراضي `shishli-ghcr` وشبكاته وVolumes غير مثبتة على أسماء Volumes المحلية؛ لا تعيد استخدام أسماء shishli_mysql أو shishli_storage.

- لا build ولا تحميل secrets أثناء البناء.
- اضبط GHCR_IMAGE_TAG إلى sha الكامل للصورتين من تشغيل نشر ناجح واحد.
- ملفات runtime الافتراضية خارج المستودع: `/etc/shishli/app.env` و`/etc/shishli/mysql.env`؛ يمكن تغيير المسارات عبر SHISHLI_RUNTIME_ENV_FILE وSHISHLI_MYSQL_ENV_FILE.
- يمكن الاسترشاد بمفاتيح `.env.docker.example` و`.env.mysql.example` عند إعداد الخادم لاحقًا؛ تطابق DB_PASSWORD وMYSQL_PASSWORD، واضبط APP_KEY وAPP_URL وCORS وHTTPS وإعدادات البريد وقت التشغيل.
- MySQL وRedis دون منافذ عامة، وNginx على loopback port 8089 افتراضيًا، انتظارًا لإعداد TLS/reverse proxy مستقبلي.
- لا يطبق migrations تلقائيًا. لا يوجد خادم أو نطاق أو TLS أو نسخ احتياطي أو اعتماد سحب GHCR تم إعداده ضمن هذه المرحلة. لا تشغل الملف على الجهاز المحلي لغرض التحقق؛ استعمل config فقط.

## مراجعة النتائج على GitHub

افتح https://github.com/Yousif-Moh-Bashir/shishli-restaurant/actions واختر **GHCR images**.

- في PR: build يعمل، gate وpublish يجب أن يكونا Skipped. راجع Build PHP-FPM وBuild Nginx وVerify وTrivy. لا تتوقع نجاح job حاليًا قبل علاج ثغرات الصور.
- بعد دمج PR: افتح CI الخاص بآخر master commit وانتظر نجاح Laravel وVue وDocker. ثم افتح GHCR images المرتبط بحدث workflow_run.
- سجل gate يعرض run ID وattempt وSHA المصرح به. قارنه بـCI وبـmaster وبوسوم الصور.
- إذا فشل CI، لن تعمل وظائف النشر. إذا فشل الفحص الأمني، يفشل build ويتخطى publish. إذا أصبح التشغيل قديمًا، تفشل البوابة أو إعادة التحقق.
- بعد رفع ناجح فقط، Summary يعرض اسمي الصور ووسمي SHA، وسجلات docker push تعرض digests. تحقق من صفحة Packages وPrivate.
- تثبت الاختبارات المحلية منطق المنع، لكن تحقق الربط بين أحداث GitHub وGITHUB_TOKEN ورفع GHCR وPrivate وGHA cache وأذونات الحزم يحتاج التشغيل الحقيقي بعد رفع الفرع ودمجه؛ لم يُدّع نجاحه محليًا.

## أوامر Git للمستخدم — لم تُنفذ

الفرع الحالي المؤكد أثناء العمل: feature/ghcr-publishing، وشجرة العمل كانت نظيفة قبل التعديل. راجع التغييرات أولًا:

```powershell
cd C:\xampp\htdocs\projects\shishli
git status --short
git diff -- .dockerignore
git diff --no-index -- NUL .github/workflows/ghcr.yml
```

الأمر الأخير للعرض فقط ويعيد exit code 1 طبيعيًا عند وجود اختلافات. راجع بقية الملفات الجديدة في المحرر. بعد المراجعة، إن اخترت إنشاء commit وPR بنفسك:

```powershell
git add .dockerignore .github/workflows/ghcr.yml .github/scripts/ghcr-gate.mjs .github/scripts/ghcr-gate.test.mjs compose.ghcr.yaml docker/php/Dockerfile docker/nginx/Dockerfile docker/ghcr/inspect-images.py docker/ghcr/test_inspect_images.py docs/GHCR_SETUP_REPORT.md docs/GHCR_SECURITY_FINDINGS.csv
git commit -m "ci: prepare gated GHCR image publishing"
git push -u origin feature/ghcr-publishing
```

افتح https://github.com/Yousif-Moh-Bashir/shishli-restaurant/compare/master...feature/ghcr-publishing ثم Create pull request. إذا ثبّت GitHub CLI وصادقت لاحقًا، البديل:

```powershell
gh pr create --repo Yousif-Moh-Bashir/shishli-restaurant --base master --head feature/ghcr-publishing --title "ci: prepare gated GHCR image publishing" --body-file docs/GHCR_SETUP_REPORT.md
```

واجهت أوامر Git داخل sandbox اختلاف ملكية Windows؛ استُخدم `git -c safe.directory=C:/xampp/htdocs/projects/shishli` لكل أمر قراءة دون تغيير Git config العام. لم يُنفذ أي أمر staging أو commit أو push.

## المصادر الرسمية

- [GitHub workflow_run: default branch, triggering run and security considerations](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_run)
- [GHCR: GITHUB_TOKEN, source labels and private initial visibility](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)
- [Aqua: incident advisory and safe pinned Trivy references](https://github.com/aquasecurity/trivy/discussions/10425)
- [Pinned Trivy action inputs and scanning behavior](https://github.com/aquasecurity/trivy-action/blob/57a97c7e7821a5776cebc9bb87c984fa69cba8f1/action.yaml)
- [Docker BuildKit GitHub Actions cache backend](https://docs.docker.com/build/cache/backends/gha/)

## Security Remediation — 16 أكتوبر 2026

### القرار وحدود النتيجة

**لا يمكن اعتماد GHCR Publishing للإطلاق حاليًا.** اجتازت صورة Nginx الجديدة سياسة الفحص، لكن PHP يحتوي 77 HIGH و3 CRITICAL. جميع نتائج PHP المانعة المتبقية بلا FixedVersion متاح لـBookworm في قاعدة الفحص المستخدمة، ولم تختف بعد apt upgrade. لم تُضف استثناءات حتى للنتائج التي يرجح عدم انطباقها على المكونات المشغلة. يبقى فشل Trivy مانعًا للرفع للصورتين معًا.

انخفاض الأعداد ليس حكمًا بأن PHP آمن، وصفر نتائج Nginx يعني صفر ما كشفه الفاحص بقاعدة البيانات المحددة، وليس ضمانًا بعدم وجود ثغرات غير معروفة. تحديث صور PHP إلى توزيعة مختلفة يحتاج خطة توافق وصيانة واختبارات مستقلة، ولن يكون تبديل اسم الصورة لمجرد تحسين العداد. لم تُستخدم حزم Debian sid/trixie داخل Bookworm أو مكتبات مترجمة يدويًا خارج دورة تحديث الموزع.

### قاعدة الثغرات والأدلة

- تقارير البداية كانت متاحة في Windows Temp: `shishli-ghcr-app-scan.json` و`shishli-ghcr-nginx-scan.json`؛ تاريخا إنشائهما محليًا 15 أكتوبر 2026 الساعة 23:58:54 و23:59:24 على الترتيب.
- Trivy v0.69.3 المستخدم سابقًا أُعيد استخدامه؛ ملفه الرسمي سبق التحقق من SHA256. نُفذ `trivy image --download-db-only` قبل الفحص الجديد. قاعدة DB schema 2 لم تحتج تنزيل نسخة أخرى لأنها حديثة وغير مستحقة للتحديث: UpdatedAt=`2026-10-15T19:09:25.631494278Z`، DownloadedAt=`2026-10-15T21:58:45.7543363Z`، NextUpdate=`2026-10-16T19:09:25.631493777Z`، وفق metadata المحلي.
- هذه الأوقات UTC؛ UpdatedAt يساوي 15 أكتوبر 21:09:25 بتوقيت الخرطوم. فحص PHP الجديد أنشئ 16 أكتوبر 00:19:11 محليًا، أي أن عمر القاعدة وقت الفحص نحو 3 ساعات و10 دقائق. **المقارنة قبل/بعد تستخدم نفس إصدار الفاحص ونفس DB UpdatedAt**، ولا يُنسب الانخفاض لتغير قاعدة البيانات.
- فُحصت الصور النهائية المحفوظة بواسطة docker save مع `--scanners vuln,secret --list-all-pkgs --severity UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL --ignore-unfixed=false`. حفظ JSON الكامل في Temp باسم `shishli-ghcr-app-after.json` و`shishli-ghcr-nginx-after.json`؛ ملفات الصور `shishli-ghcr-app-after.tar` و`shishli-ghcr-nginx-after.tar`.
- PHP بعد المعالجة: OS Debian 12.15، 77 HIGH و3 CRITICAL و133 MEDIUM و160 LOW و1 UNKNOWN. كل هذه النتائج OS؛ `composer-vendor` صفر ثغرات بكل الدرجات. Nginx: Alpine 3.24.2، صفر نتائج بكل الدرجات. الأسرار صفر في كليهما.
- [GHCR_SECURITY_FINDINGS.csv](GHCR_SECURITY_FINDINGS.csv) يحتوي صفًا لكل زوج package/advisory من الـ352 نتيجة الأصلية: الصورة، النظام، الحزمة، CVE، الخطورة، الإصدار المثبت سابقًا، الإصدار المصحح الذي أورده الموزع، حالة الإصلاح، الإصدار المثبت حاليًا، الخطورة الحالية، مصير النتيجة، ورابط advisory. لا يحتوي قيم أسرار أو مقتطفات ملفات. عدم وجود FixedVersion يعني عدم توفر إصلاح مسجل للموزع في هذه القاعدة، وليس عدم وجود حل upstream في أي نظام.

### صور الأساس الفعلية وسبب الاختيار

تمت قراءة قائمة الصور الرسمية مباشرة من docker-library/official-images، وإعادة البناء باستخدام `--pull`، مع الاحتفاظ بأسماء صور تحقق مختلفة عن الصور المحلية المستخدمة في Compose.

| المكوّن | قبل | بعد | التقييم |
| --- | --- | --- | --- |
| PHP | php:8.3-fpm-bookworm | php:8.3.35-fpm-bookworm | الإصدار العائم السابق كان بالفعل يحل إلى أحدث 8.3.35 الرسمي، لذلك لم يحدث ادعاء ترقية PHP نفسه. ثبتت نسخة patch صريحة وبقي Bookworm/glibc، مع ترقية حزم النظام وتنظيف Runtime |
| Node | node:22-bookworm-slim | node:22.23.3-bookworm-slim | الإصدار السابق كان يحل بالفعل إلى نفس patch/digest؛ بقي Node 22 وBookworm في builder، مع apt upgrade. Node لا يوجد داخل صورة Nginx النهائية |
| Nginx | nginx:1.28-alpine، فعليًا nginx 1.28.3-r1 | nginx:1.30.6-alpine3.24، فعليًا 1.30.6-r1 | انتقال إلى خط Nginx stable الحالي وإصدار Alpine المدعوم الذي توفره الصورة الرسمية؛ احتفظ بمسارات/config وmusl نفسها من حيث عائلة النظام، ولم ينقل PHP إلى Alpine |

Digests التي حُلّت أثناء البناء:

```text
php:8.3.35-fpm-bookworm    sha256:f768bd9142444f6126a690697b028c23f30878c3b628a7c355dfce75e29cc045
node:22.23.3-bookworm-slim sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392
nginx:1.30.6-alpine3.24    sha256:970364228b1e4f932abc22d321932816782cca8a20b85a77552a7cfe9acfb3b5
composer:2               sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac
```

هذه digests للبناء المرصود وليست قيودًا مثبتة في FROM؛ قد يعيد ناشر الصورة الرسمية بناء الوسم نفسه. مراجعة تحديثات patch والـdigests دوريًا لازمة. PHP 8.3 في فترة دعم الإصلاحات الأمنية حتى نهاية 2027، وNode 22 في Maintenance LTS. تحديث إصدار Alpine للصورة التي كانت Alpine أصلًا يتبع خط الصورة الرسمية المدعوم، وقد اختبر توافق Nginx وTLS وSPA وFastCGI، بدل خلط مستودعات توزيعات مختلفة أو إخفاء نتائج scanner.

### تغييرات البناء وتقليل Runtime

1. `docker/php/Dockerfile`: مرحلة runtime تستخدم apt update وapt upgrade ثم تبني جميع الامتدادات، بما فيها GD مع JPEG/PNG/WebP مرة واحدة بدل مرتين. تستخدم ldd لتحديد مكتبات /usr/local والامتدادات التي يجب الحفاظ عليها، وتعلمها manual قبل purge/auto-remove لحزم التطوير. تحتفظ بـcgi-fcgi المطلوب لـhealthcheck وCA certificates وapt ومكتبات PHP الفعلية.
2. فُصلت مرحلة dependencies المحتوية على Composer وgit وunzip. target testing يرثها ويحتفظ بتبعيات الاختبارات، أما production فيرث runtime وينسخ vendor وbootstrap/cache فقط من مرحلة production-dependencies. تأكد تنفيذًا غياب gcc وg++ وgit وcomposer عن production؛ حزم dev لا تنتقل مع vendor.
3. عدد حزم Debian المبلغ عنها في الصورة النهائية انخفض من 200 إلى 121؛ عدد حزم Composer بقي 80. المكتبات اللازمة لـSQLite وXML وTLS بقيت لأن حذفها سيكسر امتدادات PHP أو يغير عقد التشغيل، وليس حلًا شفافًا للثغرات.
4. `docker/nginx/Dockerfile`: تحديث نسخ الصور الرسمية، apt upgrade في Node builder، وapk upgrade في Nginx runtime. أضيف `npm audit --omit=dev --audit-level=high` بعد npm ci ليمنع ثغرات مكتبات الواجهة قبل bundling؛ Trivy للصورة النهائية لا يستخرج بالضرورة كل إصدارات npm من JS المجمعة. نجح audit بصفر نتائج، ولم يغير lockfile أو dependencies. بقيت جميع COPY ومسارات الواجهة والـAPI وإعدادات geocoding كما هي.
5. Workflow يحدد `pull: true` و`no-cache-filters: runtime` لـPHP، و`frontend,runtime` لـNginx، حتى لا تصبح خطوة apt/apk القديمة صالحة إلى الأبد من كاش البناء. BuildKit cache يبقى مهيأ لباقي المراحل؛ إعادة مراحل النظام والبناء تزيد زمن CI عمدًا مقابل تطبيق تصحيحات الموزع الحديثة.
6. أضيف فحص مستمر لوجود امتدادات PHP وGD formats وSQLite في الذاكرة وcgi-fcgi وphp-fpm config وغياب أدوات التطوير إلى وظيفة verify قبل Trivy. `ignore-unfixed: false` وكل شروط CI/SHA وعدم النشر في PR بقيت كما هي.

لم تتغير إصدارات Laravel أو Vue أو ملفات composer.lock/package-lock.json، ولم تتغير صلاحيات/قواعد نشر GHCR لتجاوز الفحص. redis ما زال يثبت عبر PECL كما كان؛ لم يتغير عقد استخدامه، وتحقق تحميله بعد التنظيف.

### ما أصلح فعليًا

**Nginx: جميع النتائج الأصلية الـ62 اختفت بعد تحديث نسخ الحزم**، وليست نتيجة حذف سجلات الحزم أو تجاهل الثغرات. تفاصيل كل CVE والإصدار المصحح موجودة في CSV.

| حزمة Nginx | المثبت قبل | المثبت بعد | النتائج الأصلية التي اختفت |
| --- | --- | --- | ---: |
| c-ares | 1.34.6-r0 | 1.34.8-r0 | 1 |
| curl / libcurl | 8.17.0-r1 | 8.22.0-r0 | 20 |
| libcrypto3 / libssl3 | 3.5.5-r0 | 3.5.9-r0 | 14 |
| libexpat | 2.7.5-r0 | 2.8.5-r0 | 6 |
| libuuid | 2.41.2-r0 | 2.42.3-r1 | 6 |
| libxml2 | 2.13.9-r0 | 2.13.9-r2 | 1 |
| musl / musl-utils | 1.2.5-r21 | 1.2.6-r2 | 2 |
| nghttp2-libs | 1.68.0-r0 | 1.70.0-r0 | 1 |
| nginx | 1.28.3-r1 | 1.30.6-r1 | 5 |
| pcre2 | 10.47-r0 | 10.49-r0 | 4 |
| tiff | 4.7.1-r0 | 4.7.2-r0 | 1 |
| zlib | 1.3.1-r2 | 1.3.2-r1 | 1 |

مثال حرج: CVE-2026-31789 لم يعد مبلغًا عنه في libcrypto3/libssl3 بعد الانتقال إلى 3.5.9-r0، أعلى من FixedVersion السابق 3.5.6-r0.

**PHP: أزيلت 210 نتائج من سطح Runtime بإزالة الحزم، لا بترقيع المكتبات المتبقية:**

| الحزم التي لم تعد مثبتة في production | HIGH | CRITICAL |
| --- | ---: | ---: |
| linux-libc-dev | 196 | 1 |
| libcurl3-gnutls | 5 | 0 |
| libsqlite3-dev | 2 | 1 |
| zlib1g-dev | 0 | 1 |
| libexpat1، libperl5.36، perl، perl-modules-5.36 | 4 | 0 |
| الإجمالي | 207 | 3 |

نتائج linux-libc-dev كانت تخص حزمة headers داخل الصورة، ولا تعني وجود kernel Linux كامل داخلها أو أن نواة Windows/Docker قد رُقعت. مجموع كل انخفاض H/C = 272 نتيجة، منها 62 معالجة بتحديث الحزم في Nginx و210 إزالة مكونات غير مطلوبة في PHP. لم تظهر نتائج HIGH/CRITICAL جديدة مقارنة بالمجموعات السابقة.

### تقييم مجموعات PHP المتبقية

كل الـ80 نتيجة المتبقية بلا FixedVersion لـBookworm في JSON الجديد. الحالات affected وfix_deferred وwill_not_fix حالات موزع وليست إعفاءات تلقائية. هذا تقييم لمسارات التعرض استنادًا إلى الحزم والكود والإعداد الحالي، وليس اختبار استغلال شاملًا.

| المجموعة / العدد | الإصدار المثبت | الثغرات والتقييم المحدد |
| --- | --- | --- |
| util-linux وثماني حزم مرتبطة / 48 HIGH | 2.38.1-5+deb12u3، وbsdutils يحمل epoch 1: | CVE-2026-53613 و53615 و76642 و78408 و78409 و78410 تتكرر في bsdutils/libblkid1/libmount1/libsmartcols1/libuuid1/mount/util-linux/util-linux-extra. تشمل mount/path races وpartition metadata وnsenter/cgroups. Compose لا يمنح privileged أو SYS_ADMIN ولا يعد fstab user mounts؛ هذه شروط تقلل التعرض لكنها لا ترقع الكود. لا تحذف حزم النظام الأساسية بشكل عشوائي لإسقاط النتائج. [Debian: مثال mount](https://security-tracker.debian.org/tracker/CVE-2026-53613) |
| curl/libcurl4 / 10 HIGH | 7.88.1-10+deb12u15 | CVE-2026-12064 و6276 و8286 و8458 و8927: تحقق SSH/SFTP وحالة cookies/proxy وإعادة استخدام الاتصالات/TLS. ليس كل مسار من هذه المسارات مستخدمًا في التطبيق، لكن الشبكات الخارجية والمكتبة تبقيان سطح تعرض. لا يوجد backport مسجل بعد apt upgrade؛ منع النشر قائم |
| libsqlite3-0 / 1 CRITICAL + 2 HIGH | 3.40.1-2+deb12u2 | CVE-2025-7458 وCVE-2026-11822 و11824. الأولى تتطلب القدرة على تنفيذ SQL مصمم، والأخريان تتعلقان بـFTS5/بيانات معدة. إعداد التشغيل النموذجي MySQL ولا توجد نقطة API مباشرة لتنفيذ SQL عشوائي أو تحميل قاعدة SQLite عُثر عليها في نطاق المراجعة. لكن pdo_sqlite باق للتوافق والاختبارات؛ لا يفترض أنه غير قابل للاستغلال إذا تغيرت المدخلات/الإعدادات. [Debian SQLite](https://security-tracker.debian.org/tracker/CVE-2025-7458) |
| libxml2 / 1 CRITICAL + 6 HIGH | 2.9.14+dfsg-1.3~deb12u6 | CVE-2026-6653 و74860 و86138 و86140 و86142 و86143 و86144: UAF/buffer overflows/flags في XML، وبعضها في Python bindings. لا توجد معالجة XML مباشرة في app بالمراجعة النصية، وPython bindings ليست جزءًا من المسار المعتاد، لكن PHP DOM/libxml يعتمد على المكتبة نفسها، وقد تدخل مكتبات أو بيانات XML مستقبلًا. Debian يؤجل إصلاح 6653، وTrixie نفسه لا يصلحها تلقائيًا؛ لا تنتقل إليه لمجرد العداد. [Debian libxml2](https://security-tracker.debian.org/tracker/CVE-2026-6653) |
| libssl3/openssl / 2 HIGH | 3.0.22-1~deb12u1 | CVE-2026-84782 يتعلق بإعادة إرسال handshake عبر DTLS وكشف ذاكرة/تعطل. التطبيق HTTP/FastCGI والبوابة تستخدم TLS، ولم يظهر مسار DTLS في التطبيق؛ يقل احتمال الوصول لهذا المسار لكنه لا يزيل المكتبة المصابة. [Debian OpenSSL](https://security-tracker.debian.org/tracker/CVE-2026-84782) |
| libsystemd0/libudev1 / 2 HIGH | 252.39-1~deb12u2 | CVE-2026-16742 يتعلق بخدمة systemd-homed ومستخدمين مدارين بها. هاتان مكتبتان مرتبطتان بحزم النظام، وليست خدمة systemd-homed جزءًا من خدمات Compose. هذا فرق انطباق مهم على مستوى source package، لكنه لم يتحول إلى استثناء Trivy. [Debian systemd](https://security-tracker.debian.org/tracker/CVE-2026-16742) |
| libtinfo6/ncurses-base/ncurses-bin / 3 HIGH | 6.4-4 | CVE-2025-69720: overflow في معالجة بيانات terminal. لا توجد واجهة terminal للمستخدم عبر الموقع، ولا terminfo مقدم من طلبات HTTP في الإعداد المرصود. الخطر محلي/مرتبط بمدخلات terminal؛ لا يوجد إصلاح Bookworm مسجل |
| libldap-2.5-0 / 1 HIGH | 2.5.13+dfsg-5 | CVE-2023-2953: null dereference في OpenLDAP. لا توجد ميزة LDAP مباشرة في app، لكن المكتبة قد تُستخدم عبر تبعيات شبكية. لم يثبت انتفاء الوصول لكل مسار؛ لا إعفاء |
| gzip / 1 HIGH | 1.12-1 | CVE-2026-41992: معالجة LZH قد تكشف بيانات ذاكرة. لا يستخدم التطبيق مسار LZH مباشرة بحسب مراجعة النص؛ يبقى خطر معالجة أرشيفات غير موثوقة على مستوى CLI، والإصلاح مؤجل |
| libacl1 / 1 HIGH | 2.3.1-3 | CVE-2026-54369: traversal للروابط الرمزية في عمليات ACL. خطر عمليات ملفات محلية وصلاحيات، ولا يُعد استخدام storage وحده دليلًا على انعدام الوصول. الإصلاح مؤجل |
| perl-base / 1 HIGH | 5.36.0-7+deb12u4 | CVE-2026-9538: تحليل tar عبر Archive::Tar قد يسبب DoS. أزيلت Perl الكاملة وperl-modules من production؛ لم يُعتبر انخفاض الانطباق المتوقع في perl-base برهانًا كافيًا لتجاهل تحذير الحزمة |
| zlib1g / 1 CRITICAL | 1:1.2.13.dfsg-1 | CVE-2023-45853 يتعلق بـcontrib/minizip. Debian يوضح أن ذلك الكود لا يبنى ضمن binary packages الخاصة بـBookworm، وJSON الصورة لا يتضمن package minizip. هذا مرشح قوي لنتيجة غير منطبقة على هذا binary، وليس ادعاء بأن كل zlib غير مصاب في أي توزيعة. بقي التحذير مانعًا دون ملف تجاهل؛ لا توجد موافقة على استثناء فردي موثق. [ملاحظة Debian الدقيقة](https://security-tracker.debian.org/tracker/CVE-2023-45853) |

### البناء والتحقق والتوافق

| الفحص | النتيجة |
| --- | --- |
| PHP production بـ--pull / linux/amd64 | نجح؛ `shishli-ghcr-app:remediated`، image digest `sha256:f23e88047185907fc527e9f0d34e045e3400eb24411437dde7b94e45fe82337c` |
| Nginx/Vue بـ--pull / linux/amd64 | نجح؛ `shishli-ghcr-nginx:remediated`، image digest النهائي بعد إضافة npm audit وإعادة Trivy `sha256:9b744f130a1b49314f6ee1090cf064bb93a5be27596c27edacd4a4eecff65010` |
| PHP testing target بـ--pull | نجح؛ مرحلة testing لم تنكسر بفصل Composer عن production |
| امتدادات PHP | نجح تحميل pdo_mysql وpdo_sqlite وmbstring وintl وzip وgd وbcmath وpcntl وOPcache وredis؛ واختبار JPEG/PNG/WebP في GD وSQLite memory وإنشاء Redis/ZipArchive دون اتصال شبكي |
| PHP-FPM وhealthcheck | php-fpm -t نجح؛ cgi-fcgi باق وقابل للتنفيذ؛ لا gcc/g++/git/composer في production |
| Laravel | artisan --version يعرض Laravel 13.33.0؛ API route:list نجح واحتوى api/v1؛ Unit suite نجحت في testing image داخل BuildKit دون شبكة، وSQLite في الذاكرة فقط؛ composer check-platform-reqs نجح |
| Nginx | nginx -v = 1.30.6 وnginx -t نجح؛ CA bundle موجود لمسارات geocoding HTTPS |
| HTTPS المحلي | نجح nginx -t للقالب الفعلي على Nginx الجديد داخل صورة تحقق مشتقة، باستخدام شهادة ذاتية مؤقتة مولدة داخل خطوة BuildKit ثم حذفت؛ لم تقرأ/تنسخ شهادات المستخدم أو مفاتيحه ولم تُعدل خدمة mobile-https |
| Vue/API/SPA | فاحص الصور نجح للطبقات والملفات، ولكل assets المشار إليها في index.html، ووجود /api/v1 في bundle، وFastCGI app:9000 وSPA fallback |
| npm وVue build | npm ci وبناء Vite على Node 22.23.3 نجحا؛ npm audit --omit=dev سجل صفر ثغرات؛ لا تغييرات في lockfile |
| اختبارات الواجهة | نجحت المجموعات الثلاث لـtest:delivery-location، واختبارا phone.test.mjs. احتاج npm إعادة تشغيل خارج sandbox بسبب EPERM لقراءة مسار Windows، ثم اكتملت الاختبارات بنجاح |
| Compose | config --quiet نجح للمحلي مع test profile، وللمحلي مع mobile-https وإعدادات IP/port في بيئة عملية الفحص فقط، ولـcompose.ghcr.yaml عبر --no-env-resolution |
| بوابة GitHub وطبقات الصور | اختبارات Node الأربعة عشر وPython الخمسة نجحت؛ actionlint للـCI وGHCR نجح، مع shellcheck/pyflakes غير متاحين محليًا |

استخدم بناء Vue نفسه Node 22.23.3 داخل Docker. اختبارات Node المحلية المنفصلة تستخدم Node 20.19.0 المتاح على Windows، لذلك لا تمثل إعادة تشغيل CI Ubuntu/Node22 كاملة. لم تُشغل اختبارات Laravel Feature/MySQL/Redis الكاملة محليًا لأن هذه المرحلة لا تنشئ خدمات أو تتصل بقواعد البيانات المحلية. فحص HTTPS هو فحص إعداد وشهادة اختبار، وليس إعادة نشر أو اختبار هاتف حي على صورة الإنتاج الجديدة. يلزم تشغيل CI الحقيقي على GitHub بعد رفع التغييرات بإجراء المستخدم.

الحاويات السبع الحالية لشيشلي لم تُوقف أو تُعد إنشاؤها، ولم يُنفذ compose up أو down أو restart أو migrate أو أوامر Volumes. صور التحقق الجديدة لا تستخدم tags المحلية shishli-app:local وshishli-nginx:local، فلم يتغير ما تشغله الخدمات الحالية.

### ملفات هذه المعالجة وخطوة الصيانة المطلوبة

- تعديل `docker/php/Dockerfile` و`docker/nginx/Dockerfile` و`.github/workflows/ghcr.yml`.
- تحديث هذا التقرير وإضافة `docs/GHCR_SECURITY_FINDINGS.csv` للأدلة المصنفة.
- ملفات GHCR غير المتتبعة الموجودة من المهمة السابقة محفوظة؛ لم يحدث staging أو commit أو push، ولم تتغير ملفات القفل أو Compose أو ملفات HTTPS أو مجلد Şişli أو المشاريع الأخرى.
- قبل الإطلاق: معالجة/انتظار backports لمجموعات PHP الفعلية، أو إعداد انتقال مدروس ومدعوم واختبارات توافق لنظام أحدث إذا كان سيصلح المكونات المطلوبة فعلًا. لا تحل Trixie وحدها كل libxml2؛ وبناء مكتبات core يدويًا سيضيف عبء تحديث أمني لا يبرره نجاح عداد واحد. لا تعتمد على حذف SQLite/XML إذا بقي ذلك يغير عقد التطبيق دون مراجعة.
- الحالات التي يبدو أنها غير منطبقة، مثل minizip وsystemd-homed، تحتاج قرارًا فرديًا موثقًا بأدلة binary/runtime وصلاحية زمنية قبل أي تغيير سياسة. **لم يُنفذ هذا التغيير هنا، ولا يمكن حاليًا تفعيل النشر بنجاح.**

### مصادر مراجعة المعالجة

- [الوسوم الرسمية لـPHP](https://github.com/docker-library/official-images/blob/master/library/php)، [Node](https://github.com/docker-library/official-images/blob/master/library/node)، [Nginx](https://github.com/docker-library/official-images/blob/master/library/nginx).
- [PHP Supported Versions](https://www.php.net/supported-versions.php)، [Node release lifecycle](https://nodejs.org/en/about/previous-releases)، [Nginx official releases](https://nginx.org/).
- روابط Debian الخاصة بكل مجموعة أعلاه وروابط advisory لكل صف في CSV؛ لا يستخدم تقييم عدم الانطباق مصدرًا ثانويًا كبديل عن بيانات الموزع.
