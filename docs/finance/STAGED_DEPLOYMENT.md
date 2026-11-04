# Shishli Finance — خطة نشر مرحلي مشروطة بالموافقة

تاريخ المراجعة: 2 نوفمبر 2026. المرجع الأمني الأحدث: [HARDENING.md](HARDENING.md)، وليس النتائج التاريخية غير المعالجة في FINAL_REVIEW.md.

**هذه خطة، وليست سجل تنفيذ نشر. أُجيز جرد VPS بالقراءة فقط لاحقًا، لكن الوصول إلى إعدادات SSH المحلية مُنع بالصلاحيات فتوقف الجرد قبل الاتصال؛ انظر [تقرير الجرد](VPS_INVENTORY.md). لم يُعدّل DNS أو HTTPS أو أي قاعدة إنتاج. جميع أوامر Bash أدناه مستقبلية، ولا تُنفذ كمجموعة آلية. يجب التوقف عند كل بوابة موافقة. النظام غير مجاز للنشر حاليًا.**

## 1. ما تحقق محليًا وما لم يتحقق

| العنصر | النتيجة والأثر |
| --- | --- |
| Compose الأساسي | `compose.yaml`: app، nginx، queue، scheduler، mysql 8.4، redis 7.4. الخدمات الثلاث الأولى التي تنفذ PHP تستخدم الصورة نفسها وStorage مشتركًا. يلزم إصدار PHP متسق بين app/queue/scheduler. |
| إضافة المالية | `docker/compose.finance.yaml` يضيف finance-web فقط، على `127.0.0.1:8089 -> 8080` افتراضيًا. لا يضيف MySQL أو PHP مستقلين. |
| الخدمات التي ستعاد تهيئتها | app/queue/scheduler، وnginx العملاء لإضافة حظر المالية، وfinance-web الجديدة. لا إعادة إنشاء mysql أو redis، ولا تغيير أحجامهما أو أسرارهما. |
| صور الإصدار | `docker/compose.delivery-release.yaml` يضبط صور app/queue/scheduler/nginx بأسماء إصدار ثابتة. يجب الحفاظ على إعدادات الإصدار الجاري الفعلية، وعدم الرجوع ضمنيًا إلى `:local`. يلزم override مماثل لصورة finance-web. |
| شبكات وأحجام | أسماء ثابتة `shishli_private`، `shishli_ingress`، `shishli_mysql`، `shishli_redis`، `shishli_storage`. **تغيير `-p` وحده لا يعزل اختبارًا.** ملفات اختبار المالية المستقلة لا تُدمج بملف المطعم. |
| توجيه العملاء | `docker/nginx/default.conf`: `/` و`/api/` و`/storage/` و`/up` وsitemap/robots باقية؛ الاستثناء الجديد فقط `/api/v1/finance` وما تحته يعيد 404. واجهة العملاء لا تحتوي مدخل finance.html. |
| توجيه المالية | `docker/nginx/finance.conf`: مضيف محدد، SPA مستقلة، API مالية فقط؛ بقية API وstorage مرفوضة، وHost إلى PHP مثبت. المضيف الخاطئ 404. |
| وكيل HTTPS | `docker/nginx/finance-proxy.conf.example` **قالب غير مثبت**. لا يكفي نسخه كما هو: يحتاج بوابة حجب أثناء التحقق، واستثناء ACME في HTTP لاستعمال webroot. ملفات الوكيل الفعلية وشهاداته غير معروفة محليًا. |
| البيئة المحلية | `.env.docker`: production وdebug=false، لكن APP_URL يشير إلى loopback. `back-end/.env` للتطوير local/debug=true. **لا يُنقل أي منهما إلى VPS.** لا تمثل هذه القيم بيئة الخادم الفعلية. تمت قراءة أسماء مفاتيح الأسرار دون عرض قيمها. |
| البيئة المطلوبة مستقبلًا | حافظ على APP_URL ونطاقات العملاء الحالية وAPP_KEY وDB/Redis/mail/storage. اضبط FINANCE_HOST=finance.shishlifresh.com وFINANCE_TIMEZONE=Asia/Riyadh فقط بعد المراجعة. production/debug=false لازمان. العمر المالي 480 دقيقة ثابت في config/finance.php، وليس متغير بيئة جديدًا. |
| CORS وSanctum | الواجهة المالية تستخدم نفس origin وBearer؛ لا تحتاج توسعة wildcard أو تغيير SESSION_DOMAIN أو sanctum.expiration أو stateful domains. عند إضافة origin صريح حافظ على القائمة الحالية؛ CORS ليس حاجز العزل. |
| الملفات المرشحة للإصدار | ملفات Finance في backend، bootstrap/app.php، LoginUserAction، AdminAccessService، composer.json/lock، مسارات/إعداد المالية، واجهة src/finance وfinance.html وVite/package files، Dockerfiles وNginx والـoverlays. تبقى الاختبارات في مصدر الإصدار، ولا تستخدم fixtures التشغيل على الإنتاج. |

نجح محليًا خلال إعداد هذه الخطة: فحص تركيب Compose المدمج `config --quiet`، واختبار منع تتبع الجلسات/البيئة (1/1)، و`git diff --check`. ظهر تحذير صلاحية قراءة إعداد Docker الشخصي؛ فحص Compose لا يثبت حالة Engine أو الخادم. لم تُعد هذه المرحلة اختبارات التشغيل أو البناء؛ نتائج MySQL والتزامن والمتصفح السابقة موثقة في HARDENING وأدلته، وليست اختبارًا على VPS.

في الفهرس الحالي صفر مطابقات لأسماء ملفات البيئة الفعلية ومسارات الجلسات المفحوصة. حذف 2003 ملف جلسة مجّهز ولم يُحفظ في commit، وملفات مالية كثيرة untracked. `HEAD` المحلي وقت المراجعة `d738241` **ليس حزمة نشر صالحة**: قد يحتوي ملفات المتصفح القديمة ويفتقد المالية. لا تنشر workspace خامًا، ولا تعمل `git add .` تلقائيًا. راجع commit يشمل الملفات المقصودة والحذف وقواعد التجاهل أولًا. لا تغيير لتاريخ Git ضمن هذه الخطة.

فحص الأسماء واختبار المستودع لا يثبتان غياب كل سر داخل كل ملف. فُحص 1658 ملفًا نصيًا ضمن المتتبع والملفات الجديدة غير المتجاهلة بحثًا عن أنماط private keys وAWS access IDs وGitHub/Slack tokens المحددة؛ صفر نتائج، دون طباعة قيم. هذا فحص محدود لا يشمل كل كلمات المرور أو البيانات الشخصية أو الملفات الثنائية أو تاريخ Git. بوابة الإصدار تتطلب فحص محتوى candidate بأداة كشف أسرار معتمدة، مع عرض المسارات/النوع فقط دون القيم، ومراجعة أمثلة البيئة وJSON والأدلة والمرفقات. لا تُرسل `.git` أو browser profiles أو backups أو actual `.env` أو مفاتيح TLS ضمن الحزمة/سياق البناء. `.dockerignore` يستبعد البيئة وGit وملفات المتصفح المحددة وStorage والنسخ، لكنه لا يغني عن فحص candidate.

## 2. تصنيف الأوامر وبوابات الموافقة

- **L-READ**: قراءة محلية، بلا اتصال بالخادم أو DB. منفذة جزئيًا كما أعلاه.
- **L-WRITE**: بناء/اختبار محلي معزول، يغيّر ملفات/حاويات اختبار فقط؛ ليس إذنًا لاختبار الإنتاج.
- **V-READ**: جرد VPS بالقراءة فقط مأذون به في الطلب اللاحق؛ توقف قبل الاتصال بسبب رفض صلاحية قراءة إعدادات SSH. لا يشمل أي قراءة DB أو سجلات عملاء أو كتابة ملف على VPS أو تغيير صلاحية.
- **DB-READ**: نسخ أو قراءة DB الإنتاج ولو SELECT فقط؛ ممنوع الآن ويحتاج إذنًا صريحًا.
- **V-WRITE / DNS-WRITE**: إنشاء ملفات/صور/شهادات أو تغيير خدمات أو DNS أو migrations؛ يحتاج موافقة نافذة التغيير والنسخ والتراجع. غير منفذ.
- **OPEN**: إزالة حجب الجمهور بوابة أخيرة، بموافقة مستقلة بعد تسليم نتائج التحقق.

بوابة A: اعتماد commit وحزمة مرشحة + إذن الجرد فقط. بوابة B: اعتماد نتائج الجرد والاختبار المعزول وخطة توقف المطعم والنسخ/الاستعادة، ثم إذن DNS/TLS والنشر المحجوب وترحيلي المالية. بوابة C: اعتماد نتائج التحقق ثم فتح المالية. لا تُستنتج موافقة من مرور الوقت أو من هذه الوثيقة.

## 3. جرد الخادم قبل تثبيت القيم — V-READ، بعد بوابة A فقط

لا تفترض مسار checkout أو اسم خدمة الوكيل أو وجود Certbot/age. يملأ المشغل القيم من الجرد، ولا ينسخ أسرارًا إلى السجل:

```bash
set -euo pipefail
umask 077
# املأ المسارات المعتمدة فقط؛ يرفض shell القيم غير المضبوطة.
: "${PROD_DIR:?existing production checkout absolute path}"
: "${RELEASE_DIR:?new reviewed candidate absolute path}"
: "${RELEASE_ID:?reviewed commit/release identifier}"
: "${EXPECTED_PRODUCTION_DATABASE:?verified schema name}"
test "$PROD_DIR" != "$RELEASE_DIR"
test -f "$PROD_DIR/compose.yaml"
docker compose version
docker ps --format '{{.Names}} {{.Image}} {{.Status}} {{.Ports}}'
sudo -n ss -lntp
# لا nginx -t/-T في الجرد الصارم؛ اقرأ إعدادات موجودة بقائمة سماح فقط.
command -v certbot
command -v age
```

لا تنشئ ملفًا على VPS لجمع الجرد. تجنب `nginx -t/-T` في هذه البوابة الصارمة لأن فحص التهيئة قد يحاول فتح ملفات سجلات؛ اقرأ التهيئة الموجودة فقط وصفِّها قبل إرسال المخرجات. لا تعرض أسطر Authorization أو كلمات المرور أو مفاتيح private_key؛ مسار الشهادة العامة وserver_name وlisten وproxy_pass ذات قائمة سماح كافية. افحص ملفات include المعروفة نفسها، لا سجلات الوصول. عند احتياج sudo استخدم `sudo -n` فقط وتوقف عند رفض الصلاحية؛ لا تعدّل ACL أو العضويات. تحقق من default_server وupstreams ومنافذ80/443 ووجود proxy/CDN/AAAA، وSAN/انتهاء الشهادة العامة دون فتح private key. إن كان الوكيل حاوية، تتغير مسارات الفحص؛ لا تفترض الوكيل المضيف. لا تُشغّل Artisan أو db:monitor أو migrate:status في V-READ لأن الإقلاع قد يقرأ DB أو يكتب cache/logs. لا تشغّل certbot renew أو certificates إذا تطلب كتابة logs؛ اقرأ شهادة PEM العامة مباشرة.

من labels الحاويات حدد ملفات Compose الحقيقية؛ قد يكون overlay التسليم مفعلًا بالفعل:

```bash
# APP_CID يؤخذ من قائمة docker ps ويُطابق مشروع shishli، لا تخمّن أول حاوية PHP.
: "${APP_CID:?verified current app container ID}"
docker inspect --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$APP_CID"
docker inspect --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$APP_CID"
docker inspect --format '{{.Image}}' "$APP_CID"
# OLD_FILES مصفوفة بالترتيب الأصلي لكل ملفات Compose التي أثبتها الجرد.
# مثال فقط إذا طابق الخادم: OLD_FILES=("$PROD_DIR/compose.yaml" "$PROD_DIR/docker/compose.delivery-release.yaml")
declare -p OLD_FILES >/dev/null
test "${#OLD_FILES[@]}" -gt 0
OLD_ARGS=(); for f in "${OLD_FILES[@]}"; do test -f "$f"; OLD_ARGS+=(-f "$f"); done
dc_old() { docker compose --project-directory "$PROD_DIR" -p shishli "${OLD_ARGS[@]}" "$@"; }
dc_old config --quiet
dc_old ps
```

يجب إبقاء متغيرات SHISHLI_RELEASE_* الحالية في جلسة dc_old كما هي حتى تسجيل الصور القديمة. لا تستخدم dc_old لإعادة إنشاء خدمات بعد تصدير متغيرات الإصدار الجديد؛ استخدم صور العودة المثبتة في قسم التراجع. لا تعرض `docker inspect` كاملًا أو `compose config` كاملًا؛ قد يطبعان كلمات مرور. تحقق من ENV الفعلي بطريقة قائمة سماح، لا `printenv`. سجّل image IDs للخدمات الخمس، وتحقق أن app/queue/scheduler على image ID واحد قبل اعتماد صورة app لعودتها جميعًا؛ وإلا توقف وأعد خطة صور العودة لكل خدمة. سجّل بصمات ملفات الوكيل والبيئة دون قيمها، ونسخة MySQL وحجم DB/Storage ومساحة القرص/RAM ووجود كتابات خارج Compose وwebhooks/مهام مجدولة. تعارض 8089 أو اختلاف التوجيه/التخزين أو migrations قديمة معلقة يوقف الخطة.

## 4. حزمة الإصدار والبناء والاختبار — L-WRITE ثم V-WRITE بعد الموافقة

بعد إنشاء commit مراجَع خارج هذه المرحلة، افحصه دون تشغيل أوامر المشروع على DB:

```bash
git status --short
git diff --check
git ls-tree -r --name-only "$RELEASE_ID" > candidate-paths.txt
# افحص candidate-paths سرّيًا؛ أي profile أو actual .env أو backups أو مفتاح خاص = إيقاف.
# ثم أداة secrets المعتمدة مع redact، ومراجعة جميع النتائج، لا أسماء الملفات وحدها.
git archive --format=tar --output="shishli-$RELEASE_ID.tar" "$RELEASE_ID"
sha256sum "shishli-$RELEASE_ID.tar"
```

لا تنفذ archive من HEAD الحالي غير المكتمل. لا تضمّن candidate-paths أو الأدلة التشغيلية الخاصة في صورة الإنتاج. اختبر المصدر المعتمد نفسه في بيئة مالية **مستقلة**، مع عدم وجود مشاريع اختبار سابقة تحمل الاسم نفسه قبل البدء:

```bash
docker compose --env-file /dev/null -f docker/compose.finance-test.yaml up --build --abort-on-container-exit --exit-code-from finance-tests
docker compose --env-file /dev/null -f docker/compose.finance-test.yaml stop
# دورة واجهة على بيانات اختبار جديدة فقط، وليست إعادة تشغيل fixtures على بيانات قائمة:
docker compose --env-file /dev/null -f docker/compose.finance-runtime-test.yaml up -d --build
(cd front-end && npm run test:finance:live)
docker compose --env-file /dev/null -f docker/compose.finance-runtime-test.yaml stop
(cd front-end && npm run test:finance && npm run build && npm run build:finance)
(cd back-end && composer audit --locked)
```

تستهلك الصور والاختبارات موارد؛ يفضل تنفيذها محليًا/على staging مستقل، لا مزاحمة VPS أثناء ذروة الطلبات. لا تستخدم `compose.yaml --profile test` لاستعادة بيانات المطعم، ولا تشغّل PHPUnit أو runtime setup على قاعدة الإنتاج. سجّل نتيجة/إصدار كل أمر ولا تتابع بعد فشل.

على الخادم، بعد موافقة نقل الحزمة ومطابقة checksum واستخراجها إلى RELEASE_DIR جديد، اربط ملفات البيئة الحالية فقط، دون استبدالها. المسارات التالية للإنشاء الجديد، ويتوقف ln إذا كان الملف موجودًا:

```bash
ln -s "$PROD_DIR/.env.docker" "$RELEASE_DIR/.env.docker"
ln -s "$PROD_DIR/.env.mysql" "$RELEASE_DIR/.env.mysql"
export SHISHLI_RELEASE_APP_IMAGE="shishli-app:$RELEASE_ID"
export SHISHLI_RELEASE_NGINX_IMAGE="shishli-nginx:$RELEASE_ID"
export SHISHLI_RELEASE_FINANCE_IMAGE="shishli-finance:$RELEASE_ID"
export SITE_URL=https://shishlifresh.com
export SHISHLI_HTTP_PORT=8088
export SHISHLI_FINANCE_PORT=8089
# القيم الثلاث الأخيرة تُحفظ كما أثبتها الجرد، لا تغيّر منفذ العملاء الجاري إن اختلف.
cat > "$RELEASE_DIR/compose.finance-release.yaml" <<'YAML'
services:
  app:
    environment: {FINANCE_HOST: finance.shishlifresh.com, FINANCE_TIMEZONE: Asia/Riyadh}
  queue:
    environment: {FINANCE_HOST: finance.shishlifresh.com, FINANCE_TIMEZONE: Asia/Riyadh}
  scheduler:
    environment: {FINANCE_HOST: finance.shishlifresh.com, FINANCE_TIMEZONE: Asia/Riyadh}
  finance-web:
    image: ${SHISHLI_RELEASE_FINANCE_IMAGE:?Set reviewed financial image}
YAML
dc_new() {
  docker compose --project-directory "$RELEASE_DIR" -p shishli \
    -f "$RELEASE_DIR/compose.yaml" \
    -f "$RELEASE_DIR/docker/compose.delivery-release.yaml" \
    -f "$RELEASE_DIR/docker/compose.finance.yaml" \
    -f "$RELEASE_DIR/compose.finance-release.yaml" "$@"
}
dc_new config --quiet
dc_new build app nginx finance-web
```

هذه الصيغة صالحة فقط إذا لم توجد overlays تشغيل إضافية؛ أدرج overrides الجاري الحفاظ عليها بعد مراجعة الدمج. احفظ القيم غير السرية والـoverlays النهائية لتستخدم في كل restart لاحق. لا تستخدم `up` عامًا أو `--remove-orphans` أو `down -v` أو prune. يجب ألا يظهر mysql/redis ضمن التغييرات المخططة. احتفظ بصور الإصدار السابق، لا تعمل overwrite لـ`:local`.

### إثبات عدم إعادة إنشاء MySQL وRedis

الأوامر المنشورة تستخدم أسماء الخدمات صراحة و`--no-deps`؛ أوامر build لا تعيد إنشاء حاويات جارية. تحقّق أيضًا تجريبيًا في rehearsal مستقل ثم على الإنتاج بعد موافقة النشر، لا بمجرد مراجعة النص. سجّل ID وStartedAt وRestartCount وImage وMounts لـmysql وredis قبل كل تبديل وبعده؛ يجب التطابق. ثبات ID وحده لا يكشف restart. لا تُعدّل الشبكات أو اسم المشروع/الـvolumes؛ أي اختلاف يوقف التنفيذ. لا تستخدم `--dry-run up` العام بدل مراجعة الخدمات المحددة.

```bash
# قراءة metadata فقط؛ نفذ في جلسة dc_old/dc_new المعتمدة، دون inspect كامل.
data_services_fingerprint() {
  for service in mysql redis; do
    cid=$(dc_old ps -q "$service"); test -n "$cid"
    docker inspect --format '{{.Name}} {{.Id}} {{.State.StartedAt}} {{.RestartCount}} {{.Image}} {{range .Mounts}}{{.Type}}:{{.Name}}:{{.Destination}} {{end}}' "$cid"
  done
}
# ملفات النتيجة تُكتب خارج VPS في V-READ؛ أثناء النشر فقط يجوز BACKUP_DIR على الخادم.
data_services_fingerprint > "$BACKUP_DIR/data-services.before"
# بعد كل up محدد: app ثم nginx/finance-web ثم queue/scheduler:
data_services_fingerprint > "$BACKUP_DIR/data-services.after"
cmp "$BACKUP_DIR/data-services.before" "$BACKUP_DIR/data-services.after"
```

هذا الفحص يثبت عدم إعادة الإنشاء/التشغيل إذا نُفّذ واجتاز؛ لم يُنفذ على الإنتاج. دالة dc_old هنا تستخدم ps فقط ولا تطبق صورًا. اختبار Docker المحلي يحتاج وصولًا مسموحًا؛ لم يُتجاوز رفض صلاحيات SSH لإجراء أي جرد.

## 5. DNS وHTTPS مع بقاء المالية مغلقة — DNS-WRITE/V-WRITE

بعد بوابة B فقط: أنشئ A باسم `finance` إلى IPv4 المعتمد، وAAAA فقط إذا ثبت أن IPv6 يخدم الوكيل نفسه. TTL مبدئي 300 إن سمح المزود. لا تعدّل سجلات @ أو www أو MX، ولا تغيّر أصل الطلبات أو proxy/CDN دون مراجعة. تحقق من CAA والسماح لجهة إصدار الشهادة. إذا وُجد CDN، يجب التحقق من شهادة origin أيضًا، وتعطيل cache للـHTML/API المالية؛ لا تستخدم وضع TLS غير الصارم.

```bash
# قراءة DNS بعد تعديل مصرح به؛ قارن الإجابة بعنوان الخادم المعتمد، لا بمجرد وجود إجابة.
dig +short A finance.shishlifresh.com
dig +short AAAA finance.shishlifresh.com
dig +short CAA shishlifresh.com
```

راجع ملف الوكيل الجديد وحده قبل تثبيته. المسار `/etc/nginx/conf.d/finance.conf` صالح **فقط** إن أثبت الجرد أنه included وغير موجود. لا تستبدل ملفًا جاريًا ولا تضف default_server جديدًا. إعداد HTTP الأولي:

```nginx
server {
    listen 80;
    server_name finance.shishlifresh.com;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/finance-acme;
        default_type text/plain;
        try_files $uri =404;
    }
    location / { return 503; }
}
```

لا تضع return 503/301 على مستوى server لأنه سيسبق استثناء ACME. أضف listen [::]:80/[::]:443 فقط بعد تحقق IPv6 والتوافق مع الوكيل الحالي. أوامر تركيب **الملف المراجع**، لا القالب المفتوح للجمهور:

```bash
sudo install -d -m 755 /var/www/finance-acme/.well-known/acme-challenge
: "${REVIEWED_HTTP_CONFIG:?approved temporary finance-only nginx file}"
test ! -e /etc/nginx/conf.d/finance.conf
sudo install -m 644 "$REVIEWED_HTTP_CONFIG" /etc/nginx/conf.d/finance.conf
sudo nginx -t
# لا reload إذا أخفق الفحص؛ أبق الخدمات الجارية وأزل الملف الجديد المحدد فقط بعد مراجعته.
sudo systemctl reload nginx
# تحقق أن الموقع الأساسي و /up لم يتغيرا وأن المالية 503، وأن webroot قابل للتحدي.
: "${ACME_EMAIL:?approved certificate contact}"
sudo certbot certonly --webroot -w /var/www/finance-acme \
  --cert-name finance.shishlifresh.com -d finance.shishlifresh.com \
  --email "$ACME_EMAIL" --agree-tos --non-interactive
sudo openssl x509 -in /etc/letsencrypt/live/finance.shishlifresh.com/fullchain.pem \
  -noout -dates -ext subjectAltName
```

يجب أن يحتوي SAN على finance.shishlifresh.com بالضبط أو wildcard مناسب. شهادة shishlifresh.com وحدها لا تكفي. لا تستخدم `certbot --nginx` الذي قد يحرر vhosts القائمة، ولا توقف Nginx لإصدار الشهادة. لا تطبع privkey. راجع اسم lineage الفعلي إن كان موجودًا سابقًا.

بعد الإصدار، استبدل **ملف المالية وحده** بنسخة مراجَعة من `finance-proxy.conf.example` مع: إبقاء ACME؛ إعادة توجيه باقي HTTP إلى HTTPS؛ وإضافة حجب في `location /` الخاص بـ443 قبل proxy_pass:

```nginx
allow 127.0.0.1;
allow ::1;
# يمكن إضافة عنوان تحقق معتمد فقط؛ لا تثق بعنوان من X-Forwarded-For.
deny all;
```

TLS1.2/1.3 وHSTS والترويسات الموجودة في القالب تبقى. لا تضف includeSubDomains ولا تعدل vhost العملاء. عند وجود CDN لا تسمح بكل عناوين CDN كبديل للحجب: يبقى الاختبار من loopback على origin، أو استخدم بوابة مصادقة مستقلة مراجَعة. `real_ip` قد يؤثر على allow/deny؛ راجعه ضمن الجرد. اختبر من اتصال خارجي أن المالية 403 ولا تصل إلى PHP. أبق المنفذين 8088/8089 على loopback، ولا تفتح 3306 أو9000.

```bash
sudo nginx -t
sudo systemctl reload nginx
openssl s_client -connect 127.0.0.1:443 -servername finance.shishlifresh.com \
  -verify_hostname finance.shishlifresh.com -verify_return_error </dev/null
# اختبار التجديد يغيّر حالة ACME محليًا ويتصل بالخدمة؛ يحتاج الموافقة أيضًا.
sudo certbot renew --cert-name finance.shishlifresh.com --dry-run
```

أضف deploy-hook مراجَعًا ينفذ `nginx -t` ثم reload فقط بعد تجديد ناجح، وتحقق من timer/cron الحالي بدل إنشاء مهمتين. مراقبة صلاحية الشهادة والسلسلة والتجديد شرط فتح. توثيق [Certbot الرسمي](https://eff-certbot.readthedocs.io/en/stable/using.html#webroot) يشرح webroot وdeploy hooks.

## 6. النسخة الاحتياطية والاستعادة المعزولة — DB-READ/V-WRITE

هذه أوامر إنتاج مستقبلية تحتاج إذنًا رغم أن dump يقرأ فقط. احتفظ بـAPP_KEY الحالي؛ فقدانه يمنع فك البيانات المشفرة. حساب dump يجب أن يمتلك صلاحيات routines/triggers/events اللازمة؛ إن أخفق **توقف**، لا تحذف الخيارات ولا تستخدم root إنتاج تلقائيًا. افحص وجود جداول غير InnoDB وخذ قفلًا مناسبًا بإجراء معتمد إن وجدت؛ single-transaction وحده لا يكفي لها.

قبل نافذة التوقف اجمع صور العودة والجرد والنسخ المشفرة الأولية وجرّب استعادة أولية على خادم معزول. أثناء النافذة أوقف كل كاتب خارجي متفق عليه. الترتيب أدناه يوقف الوصول إلى المطعم مؤقتًا؛ **يلزم اعتماد أثره على الطلبات والـwebhooks وإعادة تسليمها**، ولا يعني عزلًا كاملًا بين نظامي PHP. أعِد الخدمة القديمة إن طال الاختبار عن النافذة، ولا ترتجل حلولًا.

```bash
: "${BACKUP_DIR:?private absolute backup directory outside release tree}"
: "${BACKUP_AGE_RECIPIENT:?approved public age recipient}"
mkdir -m 700 "$BACKUP_DIR"
APP_CID=$(dc_old ps -q app)
NGINX_CID=$(dc_old ps -q nginx)
test -n "$APP_CID"; test -n "$NGINX_CID"
OLD_APP_ID=$(docker inspect --format '{{.Image}}' "$APP_CID")
OLD_NGINX_ID=$(docker inspect --format '{{.Image}}' "$NGINX_CID")
# استخرج اسم Volume الفعلي قبل إيقاف/إعادة إنشاء app؛ لا تعتمد على اسم مفترض.
STORAGE_VOLUME=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/storage"}}{{if eq .Type "volume"}}{{.Name}}{{end}}{{end}}{{end}}' "$APP_CID")
test -n "$STORAGE_VOLUME"
docker volume inspect --format '{{.Name}} {{.Driver}}' "$STORAGE_VOLUME"
printf '%s\n' "$OLD_APP_ID" > "$BACKUP_DIR/old-app-image-id"
printf '%s\n' "$OLD_NGINX_ID" > "$BACKUP_DIR/old-nginx-image-id"
docker image tag "$OLD_APP_ID" "shishli-app:pre-$RELEASE_ID"
docker image tag "$OLD_NGINX_ID" "shishli-nginx:pre-$RELEASE_ID"
# أرشف أسرار وإعدادات الخادم مباشرة إلى ملف مشفر، لا إلى Git أو public storage.
# OLD_FILES مثبتة بالجرد؛ هذه القائمة لا تضم مجلد backups نفسه.
sudo tar -cf - /etc/nginx /etc/letsencrypt "$PROD_DIR/.env.docker" "$PROD_DIR/.env.mysql" "${OLD_FILES[@]}" \
  | age -r "$BACKUP_AGE_RECIPIENT" -o "$BACKUP_DIR/config.tar.age"
dc_old exec -T --user www-data app php artisan down --retry=60 --no-interaction
dc_old stop -t 90 nginx
dc_old stop -t 90 queue scheduler
dc_old stop -t 90 app
# mysql وredis باقيان؛ لا يوجد تطبيق/عامل يكتب بعد تحقق إيقاف الكتاب الخارجيين.
dc_old exec -T mysql sh -c 'set -eu; test "$MYSQL_DATABASE" = "$1"; export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces --routines --triggers --events --set-gtid-purged=OFF --hex-blob "$MYSQL_DATABASE"' sh "$EXPECTED_PRODUCTION_DATABASE" \
  | gzip -c | age -r "$BACKUP_AGE_RECIPIENT" -o "$BACKUP_DIR/database.sql.gz.age"
# لا docker exec على app المتوقفة، ولا استخدام طبقة ملفاتها لأخذ Storage.
# Volume يبقى بعد stop؛ mount القراءة فقط مباشر ولا يشغّل Laravel أو entrypoint الخاص به.
docker run --rm --network none --read-only \
  --mount "type=volume,src=$STORAGE_VOLUME,dst=/backup-source,readonly" \
  --entrypoint tar "$OLD_APP_ID" -czf - -C /backup-source app \
  | age -r "$BACKUP_AGE_RECIPIENT" -o "$BACKUP_DIR/storage.tar.gz.age"
(cd "$BACKUP_DIR" && sha256sum database.sql.gz.age storage.tar.gz.age config.tar.age old-app-image-id old-nginx-image-id > SHA256SUMS)
tar -C "$BACKUP_DIR" -cf - database.sql.gz.age storage.tar.gz.age config.tar.age SHA256SUMS old-app-image-id old-nginx-image-id \
  | age -r "$BACKUP_AGE_RECIPIENT" -o "$BACKUP_DIR/snapshot.tar.age"
sha256sum "$BACKUP_DIR/snapshot.tar.age" > "$BACKUP_DIR/snapshot.tar.age.sha256"
```

`set -o pipefail` إلزامي كي لا يخفي gzip/age فشل dump/tar. توقف app لا يحذف Volume، لكنه يمنع exec داخلها؛ الإجراء الآن يثبت Volume قبل الإيقاف ويركبه مباشرة read-only في helper بلا شبكة. إذا كان mount من نوع bind أو مساره مختلفًا، **توقف** وأعد أمر النسخ بعد الجرد، ولا تنشئ volume جديدًا بديلًا. لا تعتمد على --volumes-from بعد إعادة إنشاء/إزالة الحاوية القديمة. helper الصورة المثبتة يجب أن يحتوي tar؛ أي غياب/رفض صلاحية يوقف النسخ دون إصلاح صلاحيات تلقائي.

SQL وStorage يُشفّران أثناء البث ولا يُكتبان plaintext على القرص. قد تحوي buffers/RAM/swap بيانات مؤقتة؛ استخدم مضيف استعادة موثوقًا مع swap معطل أو مشفر. لا تستخدم tee لنسخ plaintext ولا `set -x`. انقل snapshot المشفر وchecksum إلى مخزن مستقل معتمد، وتحقق من checksum هناك وقابلية فك التشفير واستعادة **الملفات الموجودة داخل النسخة المشفرة نفسها** بواسطة مسؤول الاستعادة؛ checksum دون فك/استعادة ليس كافيًا. مفاتيح age لا تُنسخ إلى Git/الأوامر/السجل. snapshot على VPS نفسه ليس نسخة مستقلة كافية. عند إخفاق pipeline اعتبر الملف .age غير مكتمل واحتفظ بآخر نسخة سابقة موثوقة؛ لا تبدأ migrations.

اختبار SQL التالي على **حاوية جديدة، بلا شبكة أو أحجام الإنتاج أو منافذ**. استعادة الاسم الأصلي داخل خادم معزول تتجنب كسر views/routines التي تؤهل أسماء المخطط. لا توصل هذا الخادم بالتطبيق الإنتاجي؛ event_scheduler معطل. يحتاج RAM تتناسب مع النسخة، ولا تفترض أن tmpfs الصغير يكفي DB فعلية.

```bash
: "${RESTORE_MYSQL_IMAGE:?locally available verified production-compatible mysql image digest}"
: "${RESTORE_TMPFS_SIZE:?approved RAM capacity e.g. 2g only if sufficient}"
[[ "$EXPECTED_PRODUCTION_DATABASE" =~ ^[A-Za-z0-9_]+$ ]]
RESTORE_NAME="shishli-finance-restore-$RELEASE_ID"
test -z "$(docker ps -aq --filter "name=^/${RESTORE_NAME}$")"
RESTORE_ENV="$BACKUP_DIR/restore-only.env"
test ! -e "$RESTORE_ENV"
(umask 077; printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_DATABASE=%s\n' "$(openssl rand -hex 32)" "$EXPECTED_PRODUCTION_DATABASE" > "$RESTORE_ENV")
docker run -d --name "$RESTORE_NAME" --network none \
  --tmpfs "/var/lib/mysql:rw,size=$RESTORE_TMPFS_SIZE" --env-file "$RESTORE_ENV" \
  "$RESTORE_MYSQL_IMAGE" --event-scheduler=OFF
RESTORE_READY=0
for attempt in $(seq 1 90); do
  if docker exec "$RESTORE_NAME" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "SELECT 1"' >/dev/null 2>&1; then RESTORE_READY=1; break; fi
  sleep 2
done
test "$RESTORE_READY" = 1
: "${RESTORE_AGE_IDENTITY:?approved protected identity path on isolated restore host only}"
# افحص snapshot الخارجي وSHA256SUMS واستخرج مكوناته المشفرة في مساحة خاصة أولًا.
# المسار identity لا يحتوي المفتاح نفسه في history، ولا يوضع على خادم الإنتاج لهذا التمرين.
age -d -i "$RESTORE_AGE_IDENTITY" "$BACKUP_DIR/database.sql.gz.age" \
  | gzip -dc | docker exec -i "$RESTORE_NAME" \
  sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"'
docker exec "$RESTORE_NAME" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqlcheck -uroot --check "$MYSQL_DATABASE"' \
  > "$BACKUP_DIR/restore-integrity.txt"
# قارن جدولًا بجدول: العدد، المفاتيح/الفهارس، views/routines/triggers/events، ومجاميع الدفع.
# قارن بالـbaseline المأخوذ في نافذة منع الكتابة، لا بعينة صفوف أو بوجود الجداول وحده.
docker stop "$RESTORE_NAME"
# إزالة هذه الحاوية التجريبية فقط بعد اعتماد النتيجة؛ لا أحجام مشتركة لحذفها.
docker rm "$RESTORE_NAME"
```

اختبر أيضًا فك storage.tar.gz.age ببث `age -d ... | tar -tzf -` دون عرض القائمة، ثم الاستعادة إلى مساحة اختبار مشفرة جديدة مقيّدة، وافحص checksums وعدد الملفات وإمكانية قراءة مرفق مالي خاص وصورة عامة من تطبيق staging معزول؛ لا تستخرج فوق storage الإنتاج. يُسجل الزمن والحجم ونتيجة الاستعادة. بعدها جهز staging مستقلًا من النسخة المستعادة لاختبار migrations وseed/reseed ومنع تغير بيانات المطعم، مع تعطيل البريد/SMS/بوابات الدفع والـqueues الخارجية؛ لا يكفي اختبار MySQL المجرد لذلك. لا تعِد استخدام fixture runtime setup لأنه ليس أداة ترقية نسخة مطعم. عند استخدام بيانات فعلية في staging تُطبق نفس ضوابط الخصوصية أو تعمية مراجَعة، ولا تُحفظ المخرجات المحتوية قيمًا في Git.

### إزالة النسخ المؤقتة بعد إثبات الاستعادة — تغيير يتطلب إذنًا

لا حذف عند الفشل ولا trap يحذف الأدلة تلقائيًا. أنشئ محضر تحقق مُعتمد خارج Git يتضمن: checksum النسخة الخارجية المطابق، نجاح age authentication، الاستعادة من النسخة الخارجية لا من المصدر، فحص SQL وStorage، وتوفر مفاتيح الاستعادة ونسخة مشفرة مستقلة وفق retention. بعد توقيع المسؤول فقط، احصر الملفات المؤقتة القديمة بالاسم وتحقق أن مساراتها المطلقة داخل مجلد النسخ المعتمد، وليست symlinks. الملفات التالية ليست من إجراء البث الجديد؛ تزال فقط إن تركها إجراء سابق:

```bash
# لا تنفذ قبل الموافقة وتوثيق نجاح الاستعادة. لا wildcards أو حذف recursive.
: "${PLAINTEXT_CLEANUP_APPROVED:?signed restore verification reference}"
BACKUP_REAL=$(realpath -e "$BACKUP_DIR")
test "$BACKUP_REAL" != /; test ! -L "$BACKUP_DIR"
for base in database.sql.gz storage.tar.gz restore-only.env; do
  target="$BACKUP_REAL/$base"
  if test -e "$target"; then
    test -f "$target"; test ! -L "$target"
    test "$(realpath -e "$target")" = "$target"
    # لا يشمل هذا القائمة أي .age أو SHA256SUMS أو مفاتيح دائمة.
    rm -- "$target"
    test ! -e "$target"
  fi
done
```

`rm` يزيل المرجع ولا يضمن محو الكتل من SSD/CoW/snapshots؛ لا ندعي أن shred يضمن ذلك. ضمان الإزالة يعتمد على تشفير مساحة المؤقتات منذ البداية ومحو مفتاحها المؤقت بطريقة إدارة المفاتيح المعتمدة، أو tmpfs مع swap معطل/مشفر وتفريغه بعد وقف كل الحاويات المستخدمة له. مساحة استعادة Storage/DB نفسها تعامل كبيانات حساسة وتزال بالطريقة نفسها بعد الفحص؛ لا `rm -rf` عام. إذا وجدت ملفات قديمة على قرص غير مشفر، اعزل الوصول وسجّل خطر البقايا واطلب خطة محو معتمدة؛ لا تغيّر صلاحيات الخادم أو تحذف snapshots تلقائيًا. احتفظ بالنسخ المشفرة وفق retention ولا تنشر محضرًا يحوي بيانات العملاء.

## 7. ترحيلا المالية فقط، ثم Seeder — V-WRITE/DB-WRITE

الجداول الجديدة: finance_revenue_sources، finance_expense_categories، finance_branch_user، finance_suppliers، finance_revenue_days، finance_revenue_lines، finance_expenses، finance_purchases، finance_audit_logs. لا ALTER أو UPDATE لجداول الطلبات/الدفع/التوصيل/المخزون في هذين الترحيلين. توجد FKs إلى users وbranches مع restrictOnDelete؛ قد تضيف قيودًا على الحذف الصلب مستقبلًا، وتحتاج أقفال metadata مؤقتة أثناء DDL.

1. `2026_11_02_160301_create_finance_tables.php`: إنشاء الجداول والقيود وDECIMAL ومفتاح الفرع/اليوم الفريد.
2. `2026_11_02_174242_add_stable_codes_to_finance_settings.php`: إضافة الأكواد الثابتة وتعبئتها ثم NOT NULL على مصادر/تصنيفات المالية فقط.
3. `Database\Seeders\FinanceSeeder` فقط: المصادر الستة والتصنيفات الخمسة وأدوار finance_entry/finance_accountant/finance_manager وصلاحيات finance.*. يعدّل جداول roles/permissions/role_has_permissions المشتركة لإنشاء المالية، ويكتب سجل migrations، ولا يمنح موظفًا صلاحية تلقائيًا. لا يشغّل DatabaseSeeder أو RolePermissionSeeder.

اختبر الأوامر ذاتها مرتين للبذر على نسخة المطعم المعزولة أولًا؛ قارن UUID/code/name/is_active وعدد المصادر/التصنيفات، وقارن منح أدوار المطعم قبل/بعد. العدد المتوقع 6/5 للإصدار الأول فقط، وليس عند وجود إعدادات مالية سابقة. لا تُعد تسمية مصدر حقيقي في الإنتاج لاختبار idempotency؛ اختبار إعادة التسمية مكانه النسخة المعزولة.

**MySQL DDL ليس معاملة قابلة للإلغاء بالكامل.** إذا أخفق إنشاء الجداول جزئيًا أو ترحيل الأكواد بعد إضافة العمود، قد تبقى تغييرات دون قيد في migrations؛ إعادة التنفيذ ليست مضمونة الأمان. أوقف العملية وقارن schema بسجل migrations، واستعد الحالة على staging وصمم إصلاحًا مراجَعًا. لا تعمل migrate:fresh أو rollback/drop تلقائيًا ولا تسجّل ترحيلًا يدويًا لتجاوز الخطأ. إذا وجدت مالية قديمة أعيدت تسميتها خارج التطبيق، راجع ربط الأكواد قبل التنفيذ؛ الترحيل يتوقف عند غموض سجل التدقيق.

بعد اجتياز الاستعادة والترقية على staging وإذن التطبيق، بينما PHP والكتاب متوقفون والمالية محجوبة:

```bash
# bypass entrypoint يمنع chown/config:cache وآثار startup غير المطلوبة في أوامر الفحص.
dc_new run --rm --no-deps --entrypoint php app artisan migrate:status --no-interaction
# راجع SQL المتوقع للترحيل الأول؛ لا تفترض نجاح pretend لترحيل يقرأ جداول لم تُنشأ بعد.
dc_new run --rm --no-deps --entrypoint php app artisan migrate \
  --path=database/migrations/2026_11_02_160301_create_finance_tables.php --force --no-interaction
dc_new run --rm --no-deps --entrypoint php app artisan migrate \
  --path=database/migrations/2026_11_02_174242_add_stable_codes_to_finance_settings.php --force --no-interaction
dc_new run --rm --no-deps --entrypoint php app artisan db:seed \
  --class='Database\Seeders\FinanceSeeder' --force --no-interaction
dc_new run --rm --no-deps --entrypoint php app artisan db:seed \
  --class='Database\Seeders\FinanceSeeder' --force --no-interaction
dc_new run --rm --no-deps --entrypoint php app artisan migrate:status --no-interaction
```

كل أمر خطوة مستقلة: افحص exit code والـschema والقيود وسجل migrations بعده قبل التالي. لا تشغّل migrate بدون path؛ أي ترحيل قديم معلق يحتاج مشروع موافقة منفصل. قارن بيانات الطلبات والمدفوعات والتوصيل والمخزون ومنح الأدوار الحالية مع baseline أثناء تجميد الكتابة؛ استثنِ فقط جداول المالية وتغييرات grants المالية وسجل migrations المقصودة. لا تكتفِ بمقارنة العدد إن كان المطلوب إثبات عدم تعديل القيم: قارن dump بيانات مرتبًا للجدول/بصماته ضمن ملفات سرية، مع استثناء timestamps/البيانات المتغيرة فقط بإجراء مراجَع.

## 8. تبديل الصور وفحوص الصحة — V-WRITE، المالية ما زالت محجوبة

```bash
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 180 app
# فحص FPM وحده لا يثبت سلامة DB أو migrations؛ راجع أيضًا حالة الترحيل والإعدادات المسموح بها.
dc_new exec -T app php artisan route:list --path=api/v1/finance --no-interaction
# nginx القديمة متوقفة؛ افحص الصور بحاوية run على الشبكة نفسها لحل اسم app.
dc_new run --rm --no-deps --entrypoint nginx nginx -t
dc_new run --rm --no-deps --entrypoint nginx finance-web -t
# ارفع الصيانة بعد تحقق app/DB؛ nginx العملاء ما زالت متوقفة وبوابة المالية مغلقة.
# يلزم ذلك قبل healthcheck الخاص بـnginx الذي يطلب /up.
dc_new exec -T --user www-data app php artisan up --no-interaction
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 nginx finance-web
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 queue scheduler
dc_new ps
curl --fail --silent --show-error https://shishlifresh.com/up
curl --fail --silent --show-error -o /dev/null https://shishlifresh.com/
curl --fail --silent --show-error -o /dev/null https://shishlifresh.com/driver/orders
curl --fail --silent --show-error -o /dev/null https://shishlifresh.com/admin
# لا تختبر النجاح بحالة HTTP للـSPA فقط: تسجيل الدخول والقراءات المعتمدة لازمان.
```

لا تتجاوز فشل صحة حقيقي. إعادة إنشاء nginx مهمة لأن FastCGI قد يحتفظ بعنوان app القديم بعد تغييره. راقب آثار startup (chown/caches) ووقت التشغيل؛ لا تترك worker قديمًا يعمل مع PHP جديد. توثيق [Docker Compose الرسمي](https://docs.docker.com/reference/cli/docker/compose/up/) يحدد --no-deps و--wait و--force-recreate؛ تحقق من دعم نسخة الخادم.

### اختبارات HTTPS الخارجية — من جهاز غير مسموح في بوابة الوكيل

تنفذ بعد إنشاء TLS المصرح به وقبل الفتح، لا أثناء جرد V-READ الحالي. المضيف المالي للـHTML وAPI يجب أن يرجع **403 إذا كانت بوابة allow/deny** أو **503 إذا كانت بوابة صيانة صريحة**؛ ليس 401. لا تستعمل --resolve إلى loopback هنا، ولا تحمل توكنًا. أي 200 أو401 أوredirect إلى login يعني وصول الجمهور إلى التطبيق قبل الإجازة ويوقف الفتح. إن كان HTTP يعيد301 إلى HTTPS، اختبر HTTPS النهائي بنفسه. اختبر من IPv4 وIPv6 إن كان AAAA منشورًا، ومن خلال CDN إن وجد دون قبول cache قديم.

```bash
EXPECTED_EXTERNAL_BLOCK=403 # أو503 فقط بحسب ملف بوابة المالية المراجع؛ لا تقبل أيًا منهما عشوائيًا.
for path in / /api/v1/finance/me; do
  test "$(curl -sS -o /dev/null -w '%{http_code}' "https://finance.shishlifresh.com$path")" = "$EXPECTED_EXTERNAL_BLOCK"
done
```

### اختبارات API الداخلية — من VPS أو مصدر مسموح صراحة

هذه تتجاوز بوابة حجب الجمهور فقط عبر المصدر المصرح به، وتصل إلى Backend الصحيح. **401 دون توكن هو المتوقع هنا**، مع ترويسات الحماية، ولا يدل على نجاح الحجب الخارجي. 403 على الطلب الداخلي يعني أن البوابة تمنع المختبِر نفسه؛ أصلح خطة الاختبار بإذن ولا تفتح الجمهور لأجل الاختبار.

```bash
test "$(curl -sS -o /dev/null -w '%{http_code}' https://shishlifresh.com/api/v1/finance/me)" = 404
test "$(curl -sS --resolve finance.shishlifresh.com:443:127.0.0.1 -H 'Accept: application/json' -o /dev/null -w '%{http_code}' https://finance.shishlifresh.com/api/v1/finance/me)" = 401
test "$(curl -sS --resolve finance.shishlifresh.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://finance.shishlifresh.com/api/v1/admin/orders)" = 404
test "$(curl -sS -H 'Host: invalid.example' -o /dev/null -w '%{http_code}' http://127.0.0.1:8089/)" = 404
curl -sS --resolve finance.shishlifresh.com:443:127.0.0.1 -H 'Accept: application/json' -D - -o /dev/null https://finance.shishlifresh.com/api/v1/finance/me
openssl s_client -connect 127.0.0.1:443 -servername finance.shishlifresh.com \
  -verify_hostname finance.shishlifresh.com -verify_return_error </dev/null
```

تأكد من no-store/nosniff/SAMEORIGIN/referrer وHSTS في 401/403. اختبر 500 على staging فقط، لا تصنع خطأ إنتاج متعمدًا. تحقق أن /storage المالية 404 والمرفقات لا تُقرأ إلا عبر API مصرح لها، وأن PDF/XLSX لا تُخزن في public storage/CDN. راقب سجلات الأخطاء بصورة منقحة، وعمر الطوابير وفشلها دون طباعة payloads. لا تستدعِ صفًا ماليًا حقيقيًا من حساب غير مخول بغرض جمع أدلة تتضمن قيمًا.

قبل التحقق الوظيفي: اختر حسابًا نشطًا مخصصًا للمالية بموافقة اسمه وهويته، مع كلمة مرور من مخزن الأسرار. الأمر التالي **يبطل جميع توكنات هذا الحساب، بما فيها غير المالية**؛ لذلك لا تختَر حساب مدير مطعم/كاشير مستخدمًا:

```bash
: "${APPROVED_FINANCE_REVIEW_EMAIL:?approved dedicated existing active account}"
dc_new exec -T --user www-data app php artisan finance:grant-manager \
  "$APPROVED_FINANCE_REVIEW_EMAIL" --force --no-interaction
```

اختبر من مصدر مسموح به في بوابة الوكيل، دون وضع كلمات مرور أو Bearer tokens في history/URL/chat: تسجيل دخول/خروج وانتهاء التوكن، me، فرع محدد، مسودة يوم وإجمالي محسوب من الخادم، تكرار اليوم، اعتماد ثم رفض تعديل مباشر، تصحيح بسبب وسجل قديم/جديد، مورد/مصروف/شراء منفصل، تقرير وتصدير PDF/XLSX، أدوار الإدخال/المحاسب/المدير والرفض لمستخدم عادي/غير مسجل. تحقق من منع توكن المالية على API المطعم ومنع التوكن العام على المالية حتى للحساب المشترك. لا تُدخل أرقام اختبار في دفاتر فعلية: الدورة الكتابية كاملة على staging؛ في الإنتاج القراءات/الأذونات ودخول حساب المراجع فقط، أو قيد افتتاح حقيقي معتمد إذا أجيز صراحة. لا ترفع dummy branches أو fixtures إلى DB المطعم.

## 9. أثر فشلي التوصيل وخطوة قبول الخطر

1. `InternalDeliveryTest::test_full_workflow_reuses_payment_and_tracks_unsettled_cash`: آخر الاستدعاءات يستعمل هوية مندوب على `/admin/orders/{uuid}/complete`؛ 403 بدل 409 متسق مع بوابة الإدارة. لا دليل هنا على كسر إتمام التسليم نفسه، إذ تمر خطوات المندوب والدفع السابقة. **لا توسع صلاحية المندوب لإرضاء الاختبار.**
2. `DeliveryExceptionsTest::test_network_order_payment_is_confirmed_by_its_driver_without_cashier`: ينشئ Order factory دون Payment؛ CollectCashPaymentAction يشترط pending offline payment مطابقًا، لذلك 422/CASH_PAYMENT_NOT_FOUND. `PlaceOrderAction` ينشئ Payment داخل المعاملة للطرق offline ومنها network؛ الاختبار المجاور الذي ينشئ Payment يتحقق من التأكيد ومنع تكرار المعاملة. لا دليل أن المالية سببت الفشل؛ كلاهما تكرر سابقًا مع شرط بوابة الإدارة الأصلي كما وثق FINAL_REVIEW.

الخطر المتبقي **ليس صفرًا**: طلبات قديمة/مستوردة/مسار آخر قد تفتقد Payment. افحص كل مسارات إنشاء الطلبات في الإصدار المعتمد واختبر checkout→network confirmation→delivered على staging المستعاد. الاستعلام التشخيصي التالي على نسخة معزولة أولًا، ثم على الإنتاج فقط بإذن DB-READ، ويطبع العدد دون هوية عميل:

```sql
SELECT COUNT(*) AS network_pending_without_offline_payment
FROM orders o
WHERE o.payment_method = 'network'
  AND o.payment_status = 'pending'
  AND o.status NOT IN ('cancelled', 'completed')
  AND NOT EXISTS (
    SELECT 1 FROM payments p
    WHERE p.order_id = o.id AND p.method = 'network'
      AND p.provider IS NULL AND p.status = 'pending'
  );
```

إذا وجد عدد غير صفري، راجع الحالات المقيّدة دون كشف بياناتها، ولا تنشئ مدفوعات آليًا أو تحوّل الحالة إلى paid. قد تكون حالات متعارضة/مدفوعة بسجل آخر؛ الاستعلام مؤشر فقط. يلزم قرار وإصلاح منفصل لبيانات/منطق التوصيل إذا أثبت الاختبار أثرًا فعليًا. لا نسمي المجموعة كلها ناجحة: الإخفاقان معروفان، وقبول نشر المالية مشروط بتوثيق عدم وجود أثر وظيفي أو قبول مسؤول النظام خطرًا محددًا، لا تجاهل اللون الأحمر. لم يُعاد تشغيل مجموعة التوصيل في إعداد هذه الخطة، ولم تُعدّل ملفاتها.

## 10. فتح النظام — OPEN، موافقة C فقط

تسلّم قبل الفتح: SHA الإصدار والصور، نتائج الاختبارات الحالية، صحة restore والنسخة الخارجية، الـmigrations المقصودة فقط، عدم تغير بيانات المطعم، TLS وسلسلة الشهادة والتجديد، اختبارات المضيف/الصلاحيات والتوكنات، اختبار عملاء/مندوب/إدارة، وموافقة قبول إخفاقات التوصيل المعروفة إن بقيت. يجب أن تكون المالية محجوبة خارجيًا طوال ذلك.

بعد الموافقة فقط: غيّر **location المالية في ملف الوكيل المالي وحده** لإزالة allow/deny الخاص بفترة التحقق، مع إبقاء المصادقة/الصلاحيات/Host في backend وNginx الداخلي وno-store. `sudo nginx -t` ثم `sudo systemctl reload nginx`. لا تعِد تحميل إعداد فشل اختباره. افحص HTTPS خارجيًا، و401 دون توكن، وحظر المالية على مضيف العملاء، وعدم كشف API المطعم من المضيف المالي. حافظ على مراقبة أخطاء 5xx/زمن API/DB/queue/مساحة backup، ثم ارفع TTL لاحقًا بموافقة إن لزم.

## 11. التراجع

شرط قبول التراجع: تنفيذ [تمرين الإصدار السابق المعزول](ROLLBACK_REHEARSAL.md) بالصورة السابقة الفعلية. يتضمن إعادة إنتاج قبول finance.* والتوكن المالي في بوابة قديمة، ثم إبطال توكنات الحسابات المحددة وسحب منح المالية فقط، وإعادة الصورة القديمة على المخطط نفسه والتحقق من401 للتوكنات المبطلة وبقاء توكن موظف المطعم صالحًا. **التمرين أُضيف ولم يُنفذ**؛ صورة الإنتاج السابقة والوصول المطلوب غير متاحين بعد توقف الجرد. لا يجوز اعتبار فحص المصدر أو وجود runbook نجاحًا تشغيليًا.

**تراجع المالية فقط، المفضل:** أعِد بوابة deny-all/503 المالية من النسخة المراجَعة ثم nginx -t/reload، وأوقف finance-web فقط:

```bash
dc_new stop -t 30 finance-web
```

يبقى backend المقوّى وحظر المالية على Nginx العملاء والجداول والأدلة والسجلات. لا تعمل migration rollback ولا تحذف بيانات مالية لتراجع واجهة. DNS يمكن أن يبقى موجّهًا إلى بوابة مغلقة؛ إرجاعه/حذفه ليس عزلًا فوريًا بسبب cache.

**خلل مشترك يستدعي صور المطعم القديمة:** أغلق المالية أولًا، ثم نافذة صيانة معتمدة وإيقاف queue/scheduler/nginx/app. راجع الحسابات التي مُنحت finance.* والتوكنات التي أنشأتها منذ النشر؛ الرجوع إلى backend قديم يفقد حاجز المالية وقد يعامل finance.* كإذن دخول إداري. لذلك **لا تستعد backend قديمًا قبل إبطال توكنات الحسابات المالية المضافة وسحب منح المالية المضافة بطريقة مراجَعة، مع الحفاظ على أدوار المطعم الأخرى**. هذا الإجراء يؤثر على الهوية المشتركة ويحتاج قائمة حسابات محددة وتدقيقًا وموافقة، ولا يُستبدل بحذف كل personal_access_tokens أو تعطيل كل المستخدمين. يفضل إبقاء backend المقوّى مع حجب المالية حتى إعداد إصلاح إن كان المطعم يعمل.

بعد تحقق هذا الشرط، استخدم صور النسخ السابقة مع الـoverlays وملفات البيئة القديمة المحفوظة، دون تشغيل أي migration عكسي:

```bash
# ضمن جلسة dc_new المراجَعة وبعد إيقاف الخدمات، وليس على تطبيق ما زال يقبل الكتابات.
export SHISHLI_RELEASE_APP_IMAGE="shishli-app:pre-$RELEASE_ID"
export SHISHLI_RELEASE_NGINX_IMAGE="shishli-nginx:pre-$RELEASE_ID"
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 180 app
dc_new exec -T --user www-data app php artisan up --no-interaction
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 nginx queue scheduler
curl --fail --silent --show-error https://shishlifresh.com/up
```

إذا تعذر بدء app الجديد قبل التراجع، أبقِ nginx والعمال متوقفين واستخدم الصور القديمة مباشرة تحت الحجب؛ لا تجعل فشل `artisan down` يفتح المرور. أعد إنشاء nginx لتحديث عنوان FPM. قاعدة البيانات تبقى بالترحيلات الإضافية حتى مراجعة التوافق. نفذ فحوص العملاء والتوصيل والصلاحيات بعد العودة، ولا تشغّل finance-web بالصورة القديمة المكشوفة.

**استعادة DB الكاملة آخر حل منفصل:** لا تستورد dump فوق قاعدة حية؛ ستفقد كل الطلبات والمدفوعات التي تلت النسخة، وليس المالية فقط. يلزم إذن حادثة مستقل وتجميد جميع الكتاب، ونسخة جنائية جديدة، وحصر المعاملات اللاحقة وخطة مصالحتها مع مزودي الدفع، ثم استعادة إلى خادم/volume جديد معزول واختبارها قبل تبديل الاتصال. لا تُدرج هنا أمر overwrite جاهزًا لقاعدة الإنتاج لأنه تجاوز خطير لنطاق الموافقة. أوامر الاستعادة المعزولة أعلاه هي إجراء التمرين المطلوب، وليست إذن تبديل اتصال الإنتاج.

## القرار ونقطة التوقف

الخطة جاهزة للمراجعة، **والنشر متوقف**. بقي: commit وحزمة خالية من البيانات الحساسة، جرد إعدادات VPS الفعلية، نتائج backup/restore للبيانات الحالية، تمرين صور العودة، اختبار ترقية نسخة المطعم وفحص الطلبات الشبكية القديمة، DNS/TLS والتجديد على origin، والتثبت المرحلي المحجوب. جرد القراءة فقط مأذون به لكنه توقف عند رفض صلاحية إعدادات SSH، دون اتصال أو محاولة تغيير الصلاحية. انظر VPS_INVENTORY.md. تُنتظر تعليمات جديدة لمعالجة عائق الوصول؛ إذن الجرد لا يجيز النسخ من DB أو تغيير الخدمات أو DNS أو النشر.

## 12. توقف الطلبات والـwebhooks ومنع الفقد

هذه خطة تغيير لاحق، ليست إذنًا لقراءة سجلات العملاء أو DB ضمن الجرد الحالي. **لا ضمان لعدم الفقد قبل التحقق من مزود الدفع الفعلي وسياسة retries وإعادة التسليم.** إذا لم توجد آلية موثوقة للإعادة/المصالحة أو تجاوزت النافذة مدة إعادة المحاولة المضمونة، لا يبدأ التوقف؛ يُعاد تصميم استقبال الأحداث عبر مخزن متين موثّق أو بوابة معتمدة في مهمة منفصلة، لا تعديل مرتجل لمسار الدفع.

1. قبل النافذة: اعتمد وقت البدء/النهاية وRTO/RPO ومسؤول المصالحة، واحصر كل قنوات إنشاء الطلبات والدفع والكتاب الخارجيين. تحقق من إعدادات المزود من مالكه دون قراءة أسراره أو افتراض أن وثائق التطوير تطابق الإنتاج. تحقق من فترة retry، سلوك استجابة503/انقطاع الاتصال، صلاحية توقيع الحدث عند الإعادة، API/dashboard لجرد العمليات وإعادة التسليم، وهل المزود يتيح replay موقعًا جديدًا بدل نسخ payload قديم. اختبار هذه الشروط في sandbox/staging أولًا.
2. ضع بوابة صيانة صريحة على ingress العملاء قبل إيقاف Nginx الداخلية، بموافقة ملف مستقل ونسخة تراجع وnginx -t/reload. أعِد503 وRetry-After للمسارات الكتابية ومنها `/api/v1/checkout` و`/api/v1/webhooks/payments/{provider}`؛ لا200 وهمية ولا صفحة SPA 200 للـwebhook. لا تُحوّل الحدث إلى login أو301 ولا تسقط توقيعه. لا تعتمد على إغلاق المنفذ وإرجاع502 بلا توثيق سياسة المزود. هذه البوابة مؤقتة خاصة بالنافذة، لا تغيّر توجيه العملاء الدائم.
3. أوقف قبول checkout جديدة وأبلغ العملاء قبل القطع. اترك الطلبات المقبولة والجارية تنهي معاملاتها ضمن مهلة مضبوطة، وأوقف العمال gracefully90ثانية وتأكد من توقفهم دون kill قسري. `artisan down` لا يلغي طلبًا بدأ بالفعل؛ nginx stop وحده قد يقطع الاستجابة مع بقاء commit. بعد مهلة التصريف تحقق من عدم وجود كتاب نشطين بإجراء DB معتمد في نافذة النشر فقط. سجل UTC T0 قبل الحجب وT1 عند اكتمال التصريف، واحتفظ بالطلبات التي استلمت تأكيدًا وreferences العمليات لدى المزود ضمن سجل خاص مقيد، دون إرسال بيانات العملاء إلى Git/chat.
4. خذ baseline نهائيًا أثناء تجميد الكتابة وbackup كما في القسم6، وأبق mysql/redis عاملين. لا تعمل FLUSHALL/queue:clear ولا تحذف failed_jobs. Redis قد يحمل أعمالًا تنتظر أو reserved؛ سجل عددها وحالاتها بإذن تشغيل لاحق، وتأكد من أن الصورة الجديدة قادرة على تنفيذها. لا تستنتج اكتمال التسليم من انتهاء queue فقط.
5. بعد التحويل: ارفع maintenance الداخلي وابدأ الخدمات الجديدة وافحص صحتها قبل إزالة بوابة الصيانة الخارجية للعملاء. أعد webhooks من مصدر موثوق وفق آلية المزود، بنفس event identity حيث أمكن، ولا تولّد حدثًا/حالة paid يدويًا. في المصدر الحالي توجد fingerprint للمزود/event ID وأقفال في HandlePaymentWebhookAction، لكن ضمان عدم التكرار يتطلب فحص المصدر الفعلي المنشور وتطبيق الاختبار عليه، لا افتراض تطابقه.
6. المصالحة بعد الإتاحة، بإذن DB منفصل: طابق كل عملية دفع في تقرير المزود للفترة `[T0 - هامش معتمد، T2 + هامش معتمد]` مع Payment وPaymentTransaction وOrder باستخدام المرجع والمبلغ والعملة والحالة؛ افحص الأحداث received/failed التي لم تصل processed، وكل محاولات ناجحة في المزود ومعلقة/مفقودة محليًا، والتكرارات. العدد/مجموع المبالغ وحدهما لا يكفيان؛ المقارنة تكون مرجعًا بمرجع دون طباعة بيانات العملاء. افحص أيضًا عدد المبيعات وعدم تكرار التحصيل لكل مرجع.
7. للطلبات: طابق كل تأكيد قُدم للعميل قبل الحجب مع Order ملتزم به بعد العودة. الطلب الذي التزم لكن انقطعت استجابته قد يعاد إرساله؛ PlaceOrderAction لا يثبت وحده وجود مفتاح idempotency عام للـcheckout، فلا تفترضه ولا تطلب من العملاء إعادة الطلب تلقائيًا. تحقق من cart/confirmation/reference عبر الدعم المعتمد قبل إعادة الإنشاء، دون حذف طلب فعلي لتسوية العدد. احتفظ بسجل الحوادث ذات المراجع الداخلية الآمنة وقرار المصالحة، لا بيانات العملاء.
8. معيار القبول: لا تأكيد عميل أو عملية مزود معتمدة دون سجل مطابق؛ لا تحصيل/طلب مكرر غير محلول؛ لا أحداث معلقة/فاشلة مجهولة؛ الطوابير عادت لمعدلها الطبيعي؛ لا زيادة غير مفسرة في5xx/تأخر الطلبات. إذا تعذرت المطابقة، يبقى قرار اكتمال النافذة معلقًا وتُفتح حادثة؛ لا تُستعد DB قديمة لأنها قد تمحو العمليات الجديدة.

تمرين staging المطلوب: طلب يبدأ قبل T0 ويكمل أثناء التصريف؛ webhook يصل أثناء503 ثم يعاد بتوقيع صالح؛ حدث مكرر بعد العودة؛ دفعة نجحت لدى sandbox بينما استجاب التطبيق بمهلة؛ ثم مصالحة تثبت عملية واحدة/طلبًا واحدًا. تأكد أن رفض التوقيع غير الصالح ما زال401 وأن duplicate لا ينشئ تحصيلًا ثانيًا. لم تُنفذ هذه السيناريوهات في جرد VPS الحالي.
