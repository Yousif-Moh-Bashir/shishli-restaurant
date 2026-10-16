# SHISHLI — PHP Runtime Security Decision

تاريخ الدراسة: 16 أكتوبر 2026، Africa/Khartoum. الفرع: `feature/ghcr-publishing`.

## القرار النهائي

**انتظار تحديثات أمنية مثبتة، والإبقاء على Bookworm مؤقتًا كخط أساس محجوب عن النشر. لا يُعتمد أي بديل من الصور المختبرة للإطلاق الإنتاجي اليوم.**

Trixie أفضل من حيث دورة الدعم المقبلة والتوافق مع glibc، لكنه ما زال يفشل بوابة الأمان. Alpine نجح وظيفيًا بعد استكمال بيانات العربية، وصغر حجمه، وسجل Trivy صفرًا؛ لكن المراجعة المستقلة كشفت كودًا غير مصحح لثغرة HIGH لا تظهر في نتيجة Alpine. لذلك لا تكفي نتيجة scanner الصفرية للتوصية بالانتقال إليه.

لم تتغير صورة الإنتاج أو Dockerfiles أو Compose أو Workflow أو قواعد البوابة خلال هذه الدراسة. الملف الوحيد المضاف للمستودع بهذه المهمة هو هذا التقرير. لم تُضف استثناءات، ولم تُنفذ commit أو push أو merge أو إعادة إنشاء/إيقاف خدمات أو أوامر قواعد بيانات/Volumes. التعديلات غير المتتبعة والمعدلة من المرحلتين السابقتين محفوظة كما كانت.

## المقارنة العملية

الصورة الأساسية PHP 8.3.35 في الحالات الثلاث، المنصة linux/amd64، وRedis extension 6.3.0، وLaravel 13.33.0، ونفس composer.lock دون تحديث التبعيات. استُخدمت صور PHP الرسمية، مع تثبيت الحزم المتاحة للموزع وترقية النظام وتنظيف أدوات البناء كما في وصفة الإنتاج الحالية.

| صورة التحقق | النظام الفعلي | HIGH | CRITICAL | ثغرات Composer | أسرار | حجم Docker المبلغ عنه | القرار |
| --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| `shishli-php-study:bookworm` | Debian 12.15 | 77 | 3 | 0 | 0 | 184.7 MB | مرفوضة للنشر؛ خط الأساس الحالي |
| `shishli-php-study:trixie` | Debian 13.7 | 54 | 1 | 0 | 0 | 198.2 MB | مرفوضة؛ انخفاض العدد لا يعالج libxml2 المتبقي |
| `shishli-php-study:alpine` | Alpine 3.24.2 | 0 | 0 | 0 | 0 | 63.6 MB | يجتاز عداد Trivy، لكنه غير معتمد بسبب دليل خارج تغطية العداد |

MB هنا مليون بايت، من حقل Size في docker image inspect، وليس استهلاك RAM أو قياسًا لسرعة التطبيق. الحجم النهائي لـAlpine يشمل **icu-data-full وtzdata**، وليس نموذجًا منزوع دعم العربية. الأحجام بالبايت: 184660742 و198214832 و63600435 على الترتيب.

نتائج الدرجات الأخرى: Bookworm يحوي 133 MEDIUM و160 LOW و1 UNKNOWN؛ Trixie يحوي 94 MEDIUM و146 LOW و1 UNKNOWN؛ Alpine لا نتائج بجميع الدرجات. ليس في ذلك ضمان لعدم وجود ثغرات غير معروفة أو غير مغطاة في بيانات الموزع.

معرّفات صور التحقق النهائية كما أعادها Docker:

```text
bookworm sha256:53d608946f58b7c8ac1b0d0dcc1dbb21badb9f790938741c5df3b632a575c163
trixie   sha256:f63a563b5905d3a6f1c1705d173e6d9e98be0437f530e8b5a6c6362a9c10ffe7
alpine   sha256:c82feb4cf4e2b6d09f16416bd67e20cda9d15bf06a256c1e18e194bea87f9baa
```

هذه صور محلية منفصلة عن `shishli-app:local`؛ لم تُرفع إلى registry ولم تستخدمها الحاويات الحالية.

## توحيد Trivy وقاعدة الثغرات

راجع العمل تقريري [GHCR_SETUP_REPORT.md](GHCR_SETUP_REPORT.md) و[GHCR_SECURITY_FINDINGS.csv](GHCR_SECURITY_FINDINGS.csv) ووصفة `docker/php/Dockerfile`، ثم أعاد بناء وفحص الصور بدل الاكتفاء بالأرقام السابقة.

- إصدار Trivy: **0.69.3**، نفس الأداة الرسمية المتحقق من checksum الخاص بها في المرحلة السابقة.
- نُسخت قاعدة DB إلى مجلد دراسة مستقل داخل Temp، ونُفذ `image --download-db-only` للتحقق من الحاجة للتحديث. لم تكن النسخة مستحقة للتحديث بعد.
- UpdatedAt: `2026-10-15T19:09:25.631494278Z`.
- DownloadedAt: `2026-10-15T21:58:45.7543363Z`.
- NextUpdate: `2026-10-16T19:09:25.631493777Z`.
- DB schema: 2. SHA256 لملف trivy.db قبل المقارنة وبعدها:

```text
32c6ed2327c56930f4814c2f243bcdbf54fe4b6b42d85533e53d0a02ebe36e4d
```

بعد التحقق استُخدم `--skip-db-update` لكل صورة مع cache-dir الدراسة نفسه لتجميد النسخة خلال المقارنة. هذا لتوحيد الدليل، وليس سياسة تعطيل التحديث في Workflow. أعلام المسح: `--scanners vuln,secret --list-all-pkgs --ignore-unfixed=false --severity UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL --format json`. لم توجد قائمة تجاهل مضافة.

أوقات إنشاء تقارير المسح النهائية بتوقيت الخرطوم:

| الصورة | CreatedAt |
| --- | --- |
| Bookworm | 16 أكتوبر 01:05:29 |
| Trixie | 16 أكتوبر 01:21:35 |
| Alpine بعد تصحيح locale | 16 أكتوبر 01:16:07 |

كل الأوقات تسبق NextUpdate، وhash قاعدة الثغرات ظل مطابقًا. تقارير JSON تفصل OS عن composer-vendor؛ لم تُستنتج سلامة المكتبات من عدد حزم OS وحده. فاحص الطبقات الموجود بالمشروع نجح أيضًا لكل صورة: ملفات Laravel المطلوبة موجودة، ولا env أو private keys أو نسخ SQL/backup ممنوعة في مسارات التطبيق بأي طبقة.

## إصدارات المكتبات وإمكان الإصلاح

| المكتبة | Bookworm | Trixie | Alpine |
| --- | --- | --- | --- |
| libc | glibc 2.36-9+deb12u14 | glibc 2.41-12+deb13u4 | musl 1.2.6-r2 |
| libxml2 | 2.9.14+dfsg-1.3~deb12u6 | 2.12.7+dfsg+really2.9.14-2.1+deb13u3 | 2.13.9-r2 |
| SQLite | 3.40.1-2+deb12u2 | 3.46.1-7+deb13u2 | 3.53.4-r0 |
| OpenSSL library | libssl3 3.0.22-1~deb12u1 | libssl3t64 3.5.7-1~deb13u3 | libssl3 3.5.9-r0 |
| libcurl | 7.88.1-10+deb12u15 | libcurl4t64 8.14.1-2+deb13u7 | 8.22.0-r0 |
| zlib | 1:1.2.13.dfsg-1 | 1:1.3.dfsg+really1.3.1-1+b1 | 1.3.2-r1 |
| ICU، بحسب PHP | 72.1 | 76.1 | 78.1، مع full data |

**لا يملك أيٌّ من نتائج HIGH/CRITICAL المتبقية في Bookworm أو Trixie FixedVersion متاحًا للتوزيعة نفسها في قاعدة المقارنة، بعد apt upgrade.** هذا لا يعني استحالة الإصلاح upstream، ولا يبرر خلط حزم sid أو إصدار Debian آخر مع النظام الحالي.

أمثلة تدل على تحسن حقيقي، لا على العدّ فقط:

- SQLite في Trixie وAlpine يتجاوز 3.42.0، الإصدار الذي تسجله [بيانات Debian لـCVE-2025-7458](https://security-tracker.debian.org/tracker/CVE-2025-7458) كإصلاح upstream؛ Bookworm بقي 3.40.1. هذه معالجة للنسخة المصابة، وليست حذف pdo_sqlite لإخفائها.
- [CVE-2026-84782 في OpenSSL](https://security-tracker.debian.org/tracker/CVE-2026-84782) له إصلاح Trixie محدد `3.5.7-1~deb13u3`، وهو المثبت فعليًا في صورة Trixie. Bookworm 3.0.22 ما زال مبلغًا عنه.
- الانتقال إلى Trixie لم يصلح [CVE-2026-6653](https://security-tracker.debian.org/tracker/CVE-2026-6653): اسم libxml2 يحتوي `really2.9.14`؛ لا يجوز التعامل معه كـupstream 2.12.7 مصحح. Alpine 2.13.9 يتجاوز حد إصلاح هذه الثغرة المحددة في 2.11.0، لكن ذلك لا يصلح تلقائيًا ثغرات XML الأحدث أدناه.

## لماذا لم يُعتمد Alpine رغم الصفر؟

النتيجة الحاسمة هي **CVE-2026-86138**، المصنفة HIGH في قاعدة مقارنة Debian. تصف [بيانات الثغرة](https://security-tracker.debian.org/tracker/CVE-2026-86138) overflow في xmlDictAddQString قبل libxml2 2.15.4، وتشير إلى [إصلاح GNOME الرسمي a4cba4b](https://github.com/GNOME/libxml2/commit/a4cba4b5b5a8c42e155ed42d2d2a44955465a2e4).

تمت مقارنة الأدلة التالية مباشرة، وليس الاعتماد على مقارنة أرقام إصدار فقط:

1. جرد صورة Alpine النهائية يثبت libxml2 **2.13.9-r2**.
2. [وصفة Alpine 3.24 الرسمية](https://gitlab.alpinelinux.org/alpine/aports/-/blob/3.24-stable/main/libxml2/APKBUILD) تستخدم أرشيف upstream 2.13.9، وتسرد patch لـCVE-2026-6732 فقط مع اختبارها. تمت قراءة النسخة الخام من GitLab ومرآة Alpine الرسمية على GitHub.
3. patch 6732 يغير **parser.c**، وليس dict.c.
4. [dict.c في upstream v2.13.9](https://github.com/GNOME/libxml2/blob/v2.13.9/dict.c) ما زال يحتوي حساب `4 * (namelen + plen + 1)` في xmlDictAddQString، ولا يحتوي guard الذي أدخله إصلاح a4cba4b. تحقق نصي محلي أكد وجود التعبير القديم وغياب guard الجديد.
5. [بيانات Alpine main الأمنية للفرع 3.24](https://secdb.alpinelinux.org/v3.24/main.json) لا تسجل CVE-2026-86138 ضمن secfixes لهذه الحزمة. Trivy لم يخرج نتيجة لها في مسار Alpine.

**الاستنتاج:** توجد أدلة على بقاء الكود غير المصحح، بينما عداد Alpine لا يظهره. لا يُنسب الصفر إلى إصلاح كامل. هذه فجوة يجب حسمها مع حزمة الموزع/تصحيحها، ولا تعالج بتعطيل فحص أو استثناء.

لم ينفذ الاختبار exploit أو يثبت وصول مدخل عميل في شيشلي إلى الدالة المصابة. حدود الذاكرة وحجم الطلب وغياب معالجة XML مباشرة ظاهرة في app قد تحد التعرض، لكنها ليست برهان عدم انطباق أو بديلًا عن الإصلاح. ثغرات XML أخرى حديثة، مثل [CVE-2026-86140](https://security-tracker.debian.org/tracker/CVE-2026-86140) و[CVE-2026-86144](https://security-tracker.debian.org/tracker/CVE-2026-86144)، تحتاج تحقق backports مستقلًا؛ لم أُعلن أن كل اختفاء لها في feed يعني إصلاحها.

هذا القيد يتعلق بمنهج اعتماد الصور أيضًا: نتائج Alpine الصفرية في التقارير السابقة، بما فيها أي صورة أخرى تحمل نسخة libxml2 نفسها، لا تُعد حكم أمان شاملًا. لم تُغير صورة Nginx أو يتوسع هذا العمل إلى ترحيلها.

## تصنيف النتائج المتبقية وقابلية الاستغلال

### قابلة للإصلاح بحزم رسمية متاحة الآن داخل نفس البديل

عدد النتائج المانعة بهذه الفئة بعد ترقية حزم النظام: **صفر** في Bookworm وTrixie بحسب FixedVersion في قاعدة المقارنة. توجد إصلاحات upstream أو في توزيعات أخرى لبعض المكونات، كما تبين الأمثلة السابقة، لكن ليست كلها backports متاحة في البدائل المختبرة. لا يضاف بناء يدوي لمكتبات core لمجرد جعل الفحص ينجح؛ ذلك ينشئ مسؤولية متابعة وصيانة أمنية جديدة.

### غير مصححة حاليًا في البدائل المختبرة

| المجموعة | Bookworm | Trixie | تقييم التعرض وحدود الدليل |
| --- | ---: | ---: | --- |
| util-linux والحزم المرتبطة | 48 HIGH، 8 حزم × 6 advisories | 36 HIGH، 9 حزم × 4 advisories | mount/path races وpartition parsing وnsenter/cgroups. Compose لا يمنح privileged أو SYS_ADMIN، لكن لا يثبت ذلك أمان المكتبات لكل استعمال محلي. تكرار CVE عبر binary packages لا يعني 48 ثغرة مستقلة |
| curl/libcurl | 10 HIGH | 4 HIGH | إعادة استخدام اتصالات وحالة cookies/proxy وTLS/SSH؛ تعتمد القابلية على البروتوكولات والمدخلات ومسار الاتصال. ليست كل الميزات مستخدمة، ولا يوجد إثبات نفي شامل |
| SQLite | 1 CRITICAL + 2 HIGH | 0 | إعداد التشغيل المعتاد MySQL، لكن SQLite باق للامتداد والاختبارات. CVE-2025-7458 يحتاج SQL مصممًا، وFTS5 له مدخلات خاصة؛ لا تُحذف المكتبة أو يُفترض عدم انطباقها تلقائيًا |
| libxml2 | 1 CRITICAL + 6 HIGH | 1 CRITICAL + 7 HIGH | UAF/overflows/XML flags. Trixie ما زال على المصدر القديم؛ نتائجه تشمل CVE-2026-86139 الإضافية. مسار Python bindings يناقش أدناه، بينما مشكلات XML الأخرى ليست كلها من ذلك النوع |
| OpenSSL | 2 HIGH | 0 | CVE-2026-84782 يخص DTLS. خدمة الموقع TLS/FastCGI ولا يوجد مسار DTLS مثبت في الدراسة؛ يقل التعرض الظاهر دون أن يعني وجود patch |
| systemd libraries | 2 HIGH | 2 HIGH | CVE-2026-16742 يخص systemd-homed؛ احتمال اختلاف انطباق source advisory عن المكتبات فقط، كما أدناه |
| ncurses/libtinfo | 3 HIGH | 3 HIGH | بيانات terminal/terminfo غير الموثوقة؛ لا واجهة terminal عبر API في الإعداد المرصود |
| libacl | 1 HIGH | 1 HIGH | روابط رمزية وعمليات ملفات وصلاحيات؛ لا يساوي استخدامها محليًا قابلية استغلال HTTP مباشرة، ولا يثبت انتفاء الخطر |
| perl-base | 1 HIGH | 1 HIGH | advisory خاص بتحليل tar في Archive::Tar؛ لا يُستنتج انطباق الوحدة من اسم source package وحده |
| gzip / OpenLDAP | 1 HIGH لكل منهما | 0 | معالجة LZH أو LDAP بمدخلات مصممة؛ لم تُثبت نقطة تعرض مباشرة من التطبيق |
| zlib/minizip | 1 CRITICAL | 0 | مرشح قوي لعدم الانطباق على binary Bookworm، دون أي استثناء مطبق |

إجمالي Bookworm: 57 نتيجة affected و22 fix_deferred و1 will_not_fix. إجمالي Trixie: 54 affected و1 fix_deferred. هذه تسميات قاعدة الموزع، وليست قبولًا للمخاطر. تفاصيل كل زوج CVE/package لخط الأساس محفوظة أصلًا في GHCR_SECURITY_FINDINGS.csv، وتقارير الدراسة الجديدة في Temp تضم تفاصيل Trixie.

### نتائج يحتمل عدم انطباقها، مع الأدلة والقيود

- **CVE-2023-45853 / zlib في Bookworm:** [Debian يوضح](https://security-tracker.debian.org/tracker/CVE-2023-45853) أن contrib/minizip المصاب لا يبنى ضمن binary packages لذلك الإصدار. هذا دليل خاص بالكود الذي ينتجه الموزع، أقوى من القول إن الموقع لا يستخدم ZIP. لا يزال التحذير في Trivy ولم يُضف استثناء له.
- **CVE-2026-16742 / libsystemd0 وlibudev1:** [advisory الموزع](https://security-tracker.debian.org/tracker/CVE-2026-16742) يصف systemd-homed؛ الخدمات المحددة بالمشروع PHP-FPM/queue/scheduler وليست homed. وجود المكتبتين لا يثبت وجود الخدمة المصابة. لا يحول ذلك الدراسة إلى VEX معتمد أو إعفاء آلي.
- **CVE-2026-74860 / Python bindings لـlibxml2:** [الوصف الرسمي](https://security-tracker.debian.org/tracker/CVE-2026-74860) يحدد Python SAX bindings. لا يعتمد تطبيق PHP على هذه bindings في المسار المختبر. لا يجوز تعميم هذا على CVE-6653 أو CVE-86138 في libxml2 نفسها.
- **perl-base / Archive::Tar:** غياب Perl الكاملة من Runtime قرينة تستحق تحليل محتويات binary والوحدة تحديدًا، وليس سببًا كافيًا لإسقاط النتيجة هنا.

هذه annotations لنتائج موجودة بالفعل، وليست أعدادًا تضاف إلى المجاميع أو قائمة تجاهل مقترحة. لا يوجد تغيير في بوابة الأمان.

## نتائج التوافق والاختبارات

جميع اختبارات التنفيذ جرت داخل خطوات BuildKit لصورة تحقق مشتقة مع `--network none`. تشغيل PHP-FPM في الاختبار كان عملية مؤقتة داخل خطوة البناء، تم إنهاؤها بعد التحقق، وليس تشغيل/إيقاف الحاويات المحلية. اختبارات قاعدة البيانات استخدمت SQLite `:memory:` فقط؛ لم تتصل بـMySQL أو Redis محليين.

| الفحص | Bookworm | Trixie | Alpine النهائي |
| --- | --- | --- | --- |
| PHP 8.3.35 وPHP-FPM config | نجح | نجح | نجح |
| pdo_mysql، pdo_sqlite، Redis، GD، intl، zip، bcmath، pcntl، OPcache | نجح | نجح | نجح |
| SQLite إنشاء جدول وكتابة وقراءة داخل الذاكرة | نجح | نجح | نجح |
| ZIP كتابة وإعادة فتح وقراءة | نجح | نجح | نجح |
| GD ترميز JPEG/PNG/WebP فعليًا، وpcntl fork/wait | نجح | نجح | نجح |
| العربية عبر IntlDateFormatter وAsia/Riyadh | يناير / 15:00 | يناير / 15:00 | يناير / 15:00 بعد full ICU |
| PHP-FPM ping عبر cgi-fcgi على loopback | pong | pong | pong |
| OPcache في طلب FastCGI فعلي | enabled | enabled | enabled |
| Laravel version وAPI route:list مع وجود api/v1 | 13.33.0 / نجح | 13.33.0 / نجح | 13.33.0 / نجح |
| composer validate --strict وcheck-platform-reqs | نجح | نجح | نجح |
| Unit suite | 162 / 365 assertions | 162 / 365 assertions | 162 / 365 assertions |
| OrderLifecycleTest + OrderAccessTest + InternalDeliveryTest | 84 / 560 assertions | 84 / 560 assertions | 84 / 560 assertions |
| MediaOptimizationTest + MediaServiceTest | 6 / 32 assertions | 6 / 32 assertions | 6 / 32 assertions |
| فحص طبقات الصورة وعدم وجود ملفات ممنوعة | نجح | نجح | نجح |

**252 اختبار Laravel ناجح لكل صورة، بإجمالي 957 assertion لكل صورة**. اختبارات الصور شملت الخدمة الفعلية وWebP والشفافية، وليس تحميل GD فقط. جرى التأكد من غياب gcc وgit وcomposer في production، ومن وجود `/usr/bin/cgi-fcgi` الذي يستخدمه healthcheck الحالي.

أزمنة Unit المرصودة: 0.40 / 0.34 / 0.69 ثانية. اختبارات الطلبات/التوصيل: 64.29 / 61.61 / 84.36 ثانية، واختبارات media نحو 3.8–4 ثوانٍ. **هذه ليست benchmark أداء للإنتاج**؛ جرت عمليات بناء واختبار متزامنة مع تفاوت الكاش واستهلاك الجهاز. لا تثبت الأرقام أن musl أبطأ أو أسرع في طلبات الموقع الحقيقية.

حدود التحقق: اختُبر توفر MySQL driver وRedis extension، وليس اتصالًا حيًا أو TLS أو DNS لهما. لم تُشغل مصفوفة Feature/MySQL/Redis الكاملة في GitHub، أو اختبارات الأحمال/الفشل أو كل الوظائف المجدولة. نجاح SQLite لا يثبت سلوك locking/concurrency في MySQL.

## مشكلات عملية ظهرت وأُصلحت في النماذج فقط

**Trixie:** الاستبدال المباشر لـFROM وحده فشل في تنظيف runtime. `dpkg-query --search` أعاد أسطر diversions، فمرر `cut -d: -f1` كلمات مثل diversion/by إلى apt-mark. استُبدل هذا الجزء في الوصفة المؤقتة بفلتر لا يقبل إلا أسطر اسم package الفعلية، ثم نجح البناء والاختبارات:

```dockerfile
| awk -F: '/^[a-z0-9][a-z0-9+.-]*(:[a-z0-9-]+)?: / {print $1}' \
| sort -u | xargs -r apt-mark manual
```

**Alpine:** النموذج الأول حمّل intl بنجاح لكنه أعاد `January` بدل العربية، وفشل الاختبار المقصود. أضيف `icu-data-full` و`tzdata` إلى runtime في الوصفة المؤقتة، وأعيد البناء والمسح واختبارات التطبيق والعربية بنجاح. لم يكتفِ القرار بأن extension_loaded(intl) يعيد true.

## الحجم وزمن البناء والصيانة

| الجانب | Bookworm | Trixie | Alpine 3.24 |
| --- | --- | --- | --- |
| حجم Docker | 184.7 MB | 198.2 MB، أكبر بنحو 7.3% | 63.6 MB، أصغر بنحو 65.6% |
| زمن بناء مرصود | 3.9 ثانية، warm cache | 312.5 ثانية للبناء الناجح بعد تصحيح الوصفة | 445 ثانية للبناء الأول؛ 36.9 ثانية لإضافة locale مع إعادة استخدام compilation cache |
| اختلاف libc | الحالي glibc | glibc أحدث | musl؛ يحتاج إعادة اختبار DNS/locales والملفات والعمليات |
| UID/GID لـwww-data داخل الصورة | 33:33 | 33:33 | 82:82 |
| نظام إدارة الحزم | apt/dpkg الحالي | apt/dpkg؛ ثبت احتياج تعديل diversion filter | apk وscanelf لاكتشاف shared libraries |
| الصيانة الأمنية | Debian LTS؛ التصحيحات المطلوبة متأخرة/غير متاحة في المقارنة | Debian stable الحالي، دورة دعم أطول؛ لا يحل ذلك كل CVE | دعم main قائم، لكن فجوة libxml2 الحالية تمنع الاعتماد على عداد scanner |

أزمنة البناء ليست مقارنة cold-cache منضبطة: Bookworm استفاد من build سابق، والبديلان نزلا صورًا/حزمًا وبنيا الامتدادات مع تنافس على CPU، كما أن زمن Alpine الأول لا يشمل تعديل locale اللاحق. لم تُمسح كاشات Docker أو الصور المستخدمة لتوحيد الأرقام؛ ذلك ليس ضروريًا لاتخاذ قرار الأمان ولا يبرر التأثير على البيئة المحلية.

دورات الدعم، وفق المصادر الرسمية يوم المراجعة:

- [PHP 8.3](https://www.php.net/supported-versions.php) في فترة إصلاحات الأمان حتى 31 ديسمبر 2027؛ تغيير نظام الصورة لا يمدد دعم PHP نفسه.
- [Bookworm انتقل إلى LTS](https://www.debian.org/News/2026/20260712)، والنهاية المعلنة 30 يونيو 2028؛ تغطية كل حزمة وحدود LTS تحتاج متابعة، لا افتراض أن كلمة Debian تعني تصحيح كل advisory فورًا.
- [Trixie](https://www.debian.org/releases/trixie/) له دعم Debian كامل حتى 9 أغسطس 2028، ثم LTS حتى 30 يونيو 2030. هو المرشح الأفضل للصيانة طويلة المدى **بعد** إصلاح blockers.
- [Alpine 3.24](https://www.alpinelinux.org/releases/) يعلن دعم main حتى 1 يونيو 2028؛ community عادة حتى الإصدار المستقر التالي. يلزم مراجعة repository لكل مكتبة لازمة عند اعتماد صورة، وعدم تعميم دعم main على كل community packages.

مخاطر Alpine الإضافية غير الممثلة بعداد CVE: اختلاف musl عن glibc، وبيانات ICU والـlocales، وUID 82 بدل 33 لملفات storage. entrypoint الحالي يقوم chown عند تشغيله root، لذلك الانتقال الفعلي قد يعيد ملكية ملفات volume؛ لم تُنفذ تلك العملية على بيانات المستخدم في الدراسة. لا تعتمد ملفات vendor أو C extensions مبنية على glibc داخل musl؛ النماذج بنت الامتدادات وثبتت التبعيات داخل كل نظام على حدة.

## إعادة التجربة والأدلة المحلية

كل الوصفات والسجلات وملفات الصور والمسح والأدلة المصدرة موجودة محليًا تحت:

```text
%TEMP%\shishli-php-runtime-decision\
```

الأسماء: `bookworm.Dockerfile` و`trixie.Dockerfile` و`alpine.Dockerfile`، و`verify.Dockerfile`، و`application-tests.Dockerfile`، و`media-tests.Dockerfile`، و`<variant>-scan.json` و`<variant>.tar` و`<variant>-build.log`، ومجلدات `<variant>-evidence` و`<variant>-test-evidence` و`<variant>-media-evidence`. توجد سجلات النموذج الأول الفاشل لـTrixie والعربية الأولى لـAlpine أيضًا. هذه أدلة مؤقتة وليست ملفات مرفوعة إلى Git؛ قد ينظف Windows Temp لاحقًا.

SHA256 لوصفات الصور النهائية:

```text
bookworm 997b3e16b35569220cfbca6a76cde964edd4c3a278c35877c7a57938819b99e4
trixie   fcc21d4e30d901365693e3fda035029218ab89e0a8bd6685849e88243d54094b
alpine   97e7a9ae3540c08cb00e8da73353488e5e913c88581e43a26c666197fdd133ab
```

hash Bookworm مطابق byte-for-byte لـdocker/php/Dockerfile أثناء الدراسة، مما يؤكد عدم تعديل الوصفة الإنتاجية. Trixie يغير FROM إلى `php:8.3.35-fpm-trixie` ويطبق فلتر dpkg أعلاه. Alpine يستخدم `php:8.3.35-fpm-alpine3.24`، ويبقي مراحل المصدر/testing/vendor/production نفسها، مع وصفة runtime التالية بدل apt:

```dockerfile
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache ca-certificates fcgi; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS pax-utils \
        icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev sqlite-dev libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j2 pdo_mysql pdo_sqlite mbstring intl zip gd bcmath pcntl opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    runDeps="$(scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
        | tr ',' '\n' | sort -u \
        | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }')"; \
    apk add --no-cache --virtual .shishli-rundeps $runDeps; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear; php -m; php-fpm -t
RUN apk add --no-cache icu-data-full tzdata
```

وفي مرحلة dependencies فقط استُخدم `apk add --no-cache unzip git` مع Composer؛ لم تنقل الأدوات إلى production. ليست هذه وصفة موصى بتطبيقها اليوم بسبب libxml2 المذكور.

مثال أوامر التحقق التي استُخدمت من جذر المشروع، مع اختلاف variant:

```powershell
$studyDir = Join-Path $env:TEMP 'shishli-php-runtime-decision'
docker build --pull --platform linux/amd64 --target production -f "$studyDir/trixie.Dockerfile" -t shishli-php-study:trixie .
docker save -o "$studyDir/trixie.tar" shishli-php-study:trixie
& "$env:TEMP/shishli-trivy-0.69.3/trivy.exe" image --cache-dir "$studyDir/cache" --skip-db-update --input "$studyDir/trixie.tar" --scanners vuln,secret --list-all-pkgs --ignore-unfixed=false --severity UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL --format json --output "$studyDir/trixie-scan.json"
docker build --network none --build-arg STUDY_IMAGE=shishli-php-study:trixie --target evidence --output "type=local,dest=$studyDir/trixie-evidence" -f "$studyDir/verify.Dockerfile" .
```

البناء الأول لكل recipe استخدم --pull. لا تعِد استخدام DB القديمة بعد فترة صلاحيتها لمجرد مطابقة الأرقام؛ حدّث snapshot ثم أعد فحص كل المرشحين بها. الصور الرسمية والوسوم راجعت من [docker-library/official-images](https://github.com/docker-library/official-images/blob/master/library/php)، وليس من صور طرف ثالث أو توزيعات غير مستقرة.

## الخطوة العملية التالية قبل GHCR

1. **إبقاء النشر محجوبًا الآن.** لا merge لتغيير نظام الصورة بهدف تمرير العداد وحده، ولا تعديل ignore-unfixed أو قبول استثناء ضمن هذه الدراسة.
2. متابعة حزم **libxml2** الرسمية والتحقق من وصول إصلاح CVE-2026-6653 وCVE-2026-86138 وبقية advisories ذات الصلة إلى الإصدار المرشح، بإصدار upstream مصحح أو backport موثق يمكن مراجعته. لا تكفي عبارة scanner 0 أو رقم إصدار ظاهر يتضمن really.
3. عند توفر التصحيحات، إعادة مقارنة المرشح المدعوم مع baseline بقاعدة Trivy محدثة وتكرار فحص المصدر في حالة فجوات feed. Trixie هو هدف الصيانة المفضل إذا أُصلحت blockers، بسبب استمرارية glibc/UID ودورة الدعم؛ Alpine يبقى خيارًا مشروطًا بحسم libxml2 واختبارات musl والملكية، لا توصية انتقال فوري.
4. تنفيذ التغيير المقترح في PR مستقل بعد إثبات الإصلاح، ثم اختبارات صورة PHP نفسها ضد **MySQL 8.4 وRedis 7.4 معزولين على GitHub Actions**، بما فيها network/DNS والاتصال وlocking/concurrency وجميع Feature tests. لا تستخدم بيانات أو حاويات الجهاز لهذه الخطوة. اختبارات الدراسة في الذاكرة ليست بديلًا لها.
5. لا نشر قبل نجاح CI لنفس master SHA، ونجاح بوابة Trivy الحالية وفحص الأسرار، وحسم دليل الثغرة غير المبلغ عنها إذا ظلت feed لا تظهرها. عندها فقط يمكن استكمال آلية GHCR الموجودة؛ لا توجد موافقة إطلاق إنتاجي أو نشر registry في هذا التقرير.

**المحصلة:** تحقق التوافق الأساسي للبديلين عمليًا، لكن لم يتحقق شرط الأمان للإطلاق. القرار هو انتظار إصلاحات مثبتة، مع عدم تغيير الإنتاج أو سياسة الفحص حاليًا.
