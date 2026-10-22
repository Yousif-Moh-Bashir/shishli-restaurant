# إصدار استثناءات التوصيل — أوامر VPS للمراجعة والتنفيذ لاحقًا

لم ينفذ هذا المستند أي نشر أو Migration إنتاج. الأوامر Bash على Linux، من مجلد
checkout الإنتاج المعتمد نفسه، مع `compose.yaml` وأسراره الموجودة. لا تنسخ أسرار
التطوير ولا تستخدم ملفات اختبار داخل Compose الإنتاج. توقف عند أي فشل.
نفّذ الكتل في جلسة Bash واحدة للحفاظ على المتغيرات والدوال. كل أوامر الإنتاج أدناه
مقترحة للتنفيذ المعتمد لاحقًا فقط؛ ليس في مرحلة تجهيز الإصدار الحالية.

## نتيجة مراجعة MySQL 8.4

- الأعمدة unsigned integer / nullable timestamp / varchar(1000)، ومفتاح UUID
  الفريد، وFKs unsigned bigint متوافقة مع المخطط الحالي وInnoDB.
- توسيع وصف التدقيق من varchar إلى text يمنع قطع أسباب المعالجة الطويلة. قد يعيد
  MySQL بناء الجدول؛ لا نفترض أنه INSTANT. افحص حجمه وخصص نافذة صيانة.
- تحد Migration انتظار metadata locks إلى 30 ثانية في جلسة MySQL؛ هذا لا يحدد
  زمن إعادة بناء الجدول نفسه. عند timeout لا تتجاوز الخطأ أو تسقط جدولًا.
- DDL يعمل implicit commit؛ فشل خطوة لاحقة لا يعيد الخطوات السابقة. أصبحت Migration
  قابلة لإعادة المحاولة بعد فشل جزئي: تفحص الأعمدة والفهرس والجدول والنوع أولًا.
  هذه الحماية تمنع التكرار، ولا تصحح نوع عمود أو schema معدلة يدويًا؛ راجع preflight
  وتعريفات الأعمدة قبل إعادة المحاولة.
- لا تغير حالات الطلبات أو المدفوعات. `down()` يرفض حذف ledger مستخدم؛ لا تستخدمه
  للتراجع في الإنتاج. يبقى توسيع description والصلاحيات عند down المقصود للاختبار.
- فحص ledger أصبح locking read حتى يرى آخر عملية ملتزمة تحت REPEATABLE READ،
  ويُقبل تكرار إعادة الإسناد المطابق قبل رفض إسناد النموذج القديم.

المراجع: [MySQL implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html)،
[InnoDB online DDL](https://dev.mysql.com/doc/refman/8.4/en/innodb-online-ddl-operations.html).
المراجعة البنيوية ليست ادعاء اجتياز MySQL؛ بوابة النشر هي نتيجة الاختبار الحقيقي أدناه.

## الصلاحيات

| الدور الافتراضي | الإذن الجديد |
|---|---|
| super_admin | view / fail / return / release / resolve_custody؛ لا يمنح cancel تلقائيًا |
| manager غير المخصص | view / fail / return / release؛ لا يمنح cancel أو resolve_custody تلقائيًا |
| manager مخصص | لا تعديل تلقائي لتخصيصه؛ منح ما يعتمد عبر لوحة الأدوار |
| cashier | تأكيد الشبكة والتحصيل والتسوية بصلاحياته الحالية؛ دون معالجة استثنائية تلقائية |
| driver | طلب تدخل وفشل ضمن driver.access وملكية الطلب؛ دون تأكيد شبكة أو إلغاء إداري |
| kitchen / customer | لا صلاحيات استثناءات |

إعادة الإسناد تستخدم drivers.reassign، وعرض القسم يتطلب orders.view أيضًا. حسم
فقد/تلف الطلب يحتاج resolve_custody مستقلة؛ لا تمنح افتراضيًا للمدير. لا تمنح
Migration صلاحية cancel تلقائيًا ولا تسحب منحة صريحة موجودة. ويحافظ
RolePermissionSeeder على منحة cancel الصريحة لـsuper_admin وmanager عند إعادة
المزامنة، مع بقائها خارج الصلاحيات الافتراضية. لا تشغّل RolePermissionSeeder
كخطوة نشر، لأنه قد يعيد مزامنة صلاحيات أخرى. راجع النتيجة عبر postflight.
إذا كانت قاعدة قد طبقت نسخة أقدم من هذا الترحيل كانت تمنح cancel تلقائيًا، فلن
يسحب الترحيل المعدل تلك التعيينات؛ لا يمكنه تمييزها بأمان عن منح صريحة. راجع
تعيينات الدورين قبل الإصدار واحسمها صراحةً بدل افتراض أن التعديل يعالج بيانات سابقة.

## 1. تثبيت الإصدار والتحقق من البيئة — قراءة/بناء محلي على VPS

المسارات والقيم REPLACE تتطلب ملئًا من سجل النشر الفعلي، وليست قيمًا مكتشفة.
المصدر المحلي يتضمن تعديلات سابقة على Checkout والصوت؛ راجع قرار تضمينها في artifact.
لا تستخدم HEAD وحده لتعريف هذا الإصدار؛ توجد ملفات معدلة وغير متتبعة دون Commit.
انقل artifact مراجعًا ومتحقق SHA256 بإجراء النقل المعتمد، دون تعديل الإصدار العامل
أثناء تقديم الطلبات. ابن الصور من مصدر مرشح منفصل إذا كان checkout يُستخدم حيًا.

```bash
set -euo pipefail
umask 077
export PROD_DIR='/REPLACE/production/checkout'
export RELEASE_ID='delivery-exceptions-20261021-r1'
export PUBLIC_URL='https://REPLACE_PRODUCTION_HOST'
export EXPECTED_PRODUCTION_DATABASE='shishli'  # طابقه مع الاتصال المعتمد، لا تفترضه
export SHISHLI_RELEASE_APP_IMAGE="shishli-app:$RELEASE_ID"
export SHISHLI_RELEASE_NGINX_IMAGE="shishli-nginx:$RELEASE_ID"
cd "$PROD_DIR"
dc_old() { docker compose -p shishli -f "$PROD_DIR/compose.yaml" "$@"; }
dc_new() { docker compose -p shishli -f "$PROD_DIR/compose.yaml" -f "$PROD_DIR/docker/compose.delivery-release.yaml" "$@"; }
docker version
docker compose version
df -h .
free -h
dc_old config --quiet
dc_new config --quiet
dc_old ps
APP_CID=$(dc_old ps -q app)
NGINX_CID=$(dc_old ps -q nginx)
test -n "$APP_CID"; test -n "$NGINX_CID"
OLD_APP_ID=$(docker inspect --format '{{.Image}}' "$APP_CID")
OLD_NGINX_ID=$(docker inspect --format '{{.Image}}' "$NGINX_CID")
for service in queue scheduler; do
  cid=$(dc_old ps -q "$service")
  test -n "$cid"
  test "$(docker inspect --format '{{.Image}}' "$cid")" = "$OLD_APP_ID"
done
docker image tag "$OLD_APP_ID" "shishli-app:pre-$RELEASE_ID"
docker image tag "$OLD_NGINX_ID" "shishli-nginx:pre-$RELEASE_ID"
dc_old exec -T mysql sh -c 'set -eu; test "$MYSQL_DATABASE" = "$1"; MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u "$MYSQL_USER" -D "$MYSQL_DATABASE" --batch' sh "$EXPECTED_PRODUCTION_DATABASE" < docker/delivery-preflight.sql
dc_new build app nginx
docker image inspect "$SHISHLI_RELEASE_APP_IMAGE" "$SHISHLI_RELEASE_NGINX_IMAGE" --format '{{.Id}}'
```

الأثر: build يستهلك CPU/RAM/قرص VPS لكنه لا يعيد تشغيل الخدمات. لا تستخدم tag موجودًا
لمرشح مختلف، ولا تنظف الصور السابقة. تأكد أن الملكية والـVolumes والأسرار وAPP_KEY
مطابقة للإنتاج، وأن MySQL 8.4 فعليًا وأن جميع الجداول المطلوبة InnoDB. إذا ظهرت
Migration الجديدة مطبقة سابقًا، توقف وراجع hash النسخة؛ لا تستبدل Migration منشورة.
لا تطبع `docker inspect` كاملًا أو `compose config` دون --quiet أو ملفات .env.

## 2. اختبار MySQL منفصل تمامًا — لا اتصال بقاعدة الإنتاج

`compose.yaml` الحالي يثبت أسماء الشبكات والـVolumes، لذا `-p` وحده ليس عزلًا.
الملف التالي standalone، دون env_file أو ports أو Volume إنتاج؛ MySQL في tmpfs
وشبكة internal خاصة. لا تدمجه بـ `-f compose.yaml`. يحتاج نحو 2.5 GiB RAM متاحة
إضافة إلى احتياطي الإنتاج؛ لا تشغله عند ضغط VPS. root كلمة مرور اختبار عشوائية فقط.

```bash
export DELIVERY_RELEASE_TAG="$RELEASE_ID"
export DELIVERY_TEST_ROOT_PASSWORD=$(openssl rand -hex 32)
export TEST_PROJECT="shishli-delivery-test-$RELEASE_ID"
tc() { docker compose --env-file /dev/null -p "$TEST_PROJECT" -f "$PROD_DIR/docker/compose.delivery-release-test.yaml" "$@"; }
tc config --quiet
tc build delivery-tests
tc up -d --wait --wait-timeout 180 delivery-mysql-test
tc exec -T delivery-mysql-test sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root --batch -e "SELECT VERSION(), @@default_storage_engine"'
mkdir -p "$PROD_DIR/docker/backups/release-validation-$RELEASE_ID"
VALIDATION_DIR="$PROD_DIR/docker/backups/release-validation-$RELEASE_ID"
for attempt in 1 2 3; do
  tc run --rm --no-deps delivery-tests > "$VALIDATION_DIR/mysql-tests-$attempt.log" 2>&1
done
```

إذا فشل أي run: توقف ولا تنشر؛ احتفظ بالسجل المقيد. راجع exit code والنتيجة
الفعلية، ولا تعتبر `skipped` اجتيازًا لاختبار التزامن. توجد ثلاثة اختبارات SQLite فقط
يتخطاها MySQL عمدًا (حقن فشل التدقيق، وترقية SQLite، واستئنافها)؛ يقابلها سيناريو
ترقية MySQL الحقيقي هنا.
اختبارات التزامن التسعة لا يجوز تخطيها، ولا اختبارات قبول المندوب المتزامنة.

اختبار MySQL الجديد يستخدم عمليتي PHP واتصالي DB مستقلين وحاجز بدء، ويثبت أن
العمليتين تنتظران أقفال المندوب المحتجزة قبل إطلاقهما. يغطي نفس المفتاح/نفس النية،
نفس المفتاح/نية مختلفة، مفتاحين لنفس المراجعة، تعارض مفتاح بين طلبين، التسليم مقابل
الإرجاع، إعادة الإسناد مقابل القبول، إعادة الإسناد المكررة، تأكيد العودة المكرر،
وترقية مخطط قديم بعد فشل جزئي وإعادة التشغيل. كل قاعدة سباق اسمها عشوائي محصور
في shishli_delivery_race_* وتُحذف في النهاية على خادم الاختبار فقط.

## 3. نافذة الصيانة والنسخة النهائية — يؤثر على توفر الإنتاج

لا تبدأها قبل اجتياز الاختبار واعتماد نافذة التوقف والتحقق من وجود النسخ السابقة
ومسؤول التراجع. أوقف أي كاتب خارجي معروف إلى DB؛ الخطوات أدناه تغطي خدمات Compose
الموجودة فقط. إخفاق طلبات Webhook أثناء الصيانة يحتاج إعادة تسليم من المزود، لا
تأكيد دفع يدوي أو replay عشوائي. أبقِ mysql وredis عاملين طوال النافذة.

```bash
BACKUP_DIR="$PROD_DIR/docker/backups/$RELEASE_ID-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$BACKUP_DIR"
printf '%s\n' "$OLD_APP_ID" > "$BACKUP_DIR/old-app-image-id"
printf '%s\n' "$OLD_NGINX_ID" > "$BACKUP_DIR/old-nginx-image-id"
dc_old exec -T --user www-data app php artisan down --retry=60 --no-interaction
dc_old stop -t 90 nginx
dc_old stop -t 90 queue scheduler
dc_old stop -t 90 app
# لم يعد التطبيق/الـworkers يكتبان. النسخة تقرأ DB فقط؛ tar يركب Storage للقراءة فقط.
dc_old exec -T mysql sh -c 'set -eu; test "$MYSQL_DATABASE" = "$1"; export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces --routines --triggers --events --set-gtid-purged=OFF "$MYSQL_DATABASE"' sh "$EXPECTED_PRODUCTION_DATABASE" | gzip -c > "$BACKUP_DIR/database.sql.gz"
docker run --rm --network none --volumes-from "$APP_CID":ro --entrypoint tar "$OLD_APP_ID" -czf - -C /var/www/html/storage app > "$BACKUP_DIR/storage.tar.gz"
gzip -t "$BACKUP_DIR/database.sql.gz"
tar -tzf "$BACKUP_DIR/storage.tar.gz" > /dev/null
(cd "$BACKUP_DIR" && sha256sum database.sql.gz storage.tar.gz > SHA256SUMS)
dc_old exec -T mysql sh -c 'set -eu; test "$MYSQL_DATABASE" = "$1"; MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u "$MYSQL_USER" -D "$MYSQL_DATABASE" --batch' sh "$EXPECTED_PRODUCTION_DATABASE" < docker/delivery-preflight.sql > "$BACKUP_DIR/preflight.txt"
```

إذا تعذر dump لصلاحيات routines/events، توقف وأعد التوفر بالإصدار القديم؛ لا تحذف
هذه الأجزاء من backup بصمت ولا تستخدم root إنتاج تلقائيًا. هذه الملفات تحتوي بيانات
خاصة: لا تعرضها في chat أو git أو public storage. احتفظ بـAPP_KEY والأسرار في مخزنها
المشفر المعتمد؛ backup Storage وSQL لا يغني عنها. انسخ backup مشفرًا خارج VPS بإجراء
النسخ المعتمد وتحقق من checksum. لا تتابع إن لم توجد نسخة مستقلة قابلة للاستعادة.

أوامر ملموسة عند اعتماد age وSSH في التشغيل (تحقق من توفرهما قبل نافذة الصيانة؛
لا تثبّت أدوات أثناء النافذة). مفتاح age هنا recipient عام وليس المفتاح الخاص:

```bash
command -v age
command -v scp
export BACKUP_AGE_RECIPIENT='REPLACE_APPROVED_AGE_PUBLIC_RECIPIENT'
export BACKUP_REMOTE='REPLACE_BACKUP_USER@REPLACE_BACKUP_HOST:/REPLACE/approved/shishli'
tar -C "$BACKUP_DIR" -cf - database.sql.gz storage.tar.gz SHA256SUMS preflight.txt old-app-image-id old-nginx-image-id | age -r "$BACKUP_AGE_RECIPIENT" -o "$BACKUP_DIR/snapshot.tar.age"
(cd "$BACKUP_DIR" && sha256sum snapshot.tar.age > snapshot.tar.age.sha256)
scp "$BACKUP_DIR/snapshot.tar.age" "$BACKUP_DIR/snapshot.tar.age.sha256" "${BACKUP_REMOTE%/}/"
scp "${BACKUP_REMOTE%/}/snapshot.tar.age" "$VALIDATION_DIR/offsite-roundtrip.age"
cmp "$BACKUP_DIR/snapshot.tar.age" "$VALIDATION_DIR/offsite-roundtrip.age"
```

تحقق من هوية SSH المعتمدة؛ لا تستخدم StrictHostKeyChecking=no. يجب أيضًا إثبات
فك التشفير بمفتاح الاستعادة الخاص على جهاز استعادة مخوّل؛ لا تضع ذلك المفتاح على
VPS ولا في الأوامر أو المستودع. إذا كانت المؤسسة تستخدم خدمة backup أخرى، استبدل
هذه الكتلة بإجراء التشفير والتحقق المعتمد لديها قبل متابعة النشر.

## 4. إثبات استعادة SQL داخل خادم الاختبار فقط

```bash
tc exec -T delivery-mysql-test sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root -e "CREATE DATABASE shishli_delivery_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"'
gzip -dc "$BACKUP_DIR/database.sql.gz" | tc exec -T delivery-mysql-test sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root -D shishli_delivery_restore'
tc exec -T delivery-mysql-test sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root -D shishli_delivery_restore --batch' < docker/delivery-preflight.sql > "$BACKUP_DIR/restore-preflight.txt"
awk '$1 ~ /^(orders|payments|payment_transactions|driver_cash_collections)$/ && NF==2 {print}' "$BACKUP_DIR/preflight.txt" > "$BACKUP_DIR/before-counts.txt"
awk '$1 ~ /^(orders|payments|payment_transactions|driver_cash_collections)$/ && NF==2 {print}' "$BACKUP_DIR/restore-preflight.txt" > "$BACKUP_DIR/restored-counts.txt"
test "$(wc -l < "$BACKUP_DIR/before-counts.txt")" -eq 4
diff -u "$BACKUP_DIR/before-counts.txt" "$BACKUP_DIR/restored-counts.txt"
```

قارن counts وتعريفات الجداول من preflight مع restore-preflight، وتحقق من الجداول
والـroutines/triggers/events المطلوبة. لا تفعّل event_scheduler على نسخة الاستعادة.
فك Storage في مجلد اختبار خاص فقط إذا أردت اختبار القراءة؛ لا تفك الأرشيف فوق
Volume الإنتاج. لا تشغّل التطبيق التجريبي على البيانات المستعادة أو ترسل إشعارات.
إذا فشل التحقق فلا تنفذ Migration الإنتاج. عند إعادة هذه الخطوة استخدم مشروع اختبار
جديدًا؛ لا تستعمل DROP على اسم قاعدة تختاره يدويًا.

## 5. تطبيق Migration — تغيير مخطط وصلاحيات قاعدة الإنتاج

```bash
dc_new run --rm --no-deps --user www-data --entrypoint php app artisan migrate:status --no-interaction
# يجب ألا توجد ترحيلات مطلوبة غير التي روجعت لهذا الإصدار. --path يمنع تطبيق غيرها.
dc_new run --rm --no-deps --user www-data --entrypoint php app artisan migrate --force --no-interaction --path=database/migrations/2026_10_21_201116_add_delivery_exception_management.php
dc_new run --rm --no-deps --user www-data --entrypoint php app artisan permission:cache-reset --no-interaction
dc_old exec -T mysql sh -c 'set -eu; test "$MYSQL_DATABASE" = "$1"; MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u "$MYSQL_USER" -D "$MYSQL_DATABASE" --batch' sh "$EXPECTED_PRODUCTION_DATABASE" < docker/delivery-postflight.sql > "$BACKUP_DIR/postflight-before-open.txt"
awk '$1 ~ /^(orders|payments|payment_transactions|driver_cash_collections)$/ && NF==2 {print}' "$BACKUP_DIR/postflight-before-open.txt" > "$BACKUP_DIR/after-counts.txt"
diff -u "$BACKUP_DIR/before-counts.txt" "$BACKUP_DIR/after-counts.txt"
dc_new run --rm --no-deps --user www-data --entrypoint php app artisan route:list --path=api/v1/admin/delivery-exceptions --no-interaction
```

قارن counts بـpreflight؛ يجب ألا يتغير عدد الطلبات والمدفوعات والمعاملات والتحصيلات.
راجع تعريف UUID unique والحقول والصلاحيات. لا تستخدم migrate:fresh أو seed أو
migrate:rollback. إن فشلت Migration بعد DDL، راجع schema ورسالة الخطأ وسبب الفشل،
ثم أعد الأمر نفسه فقط بعد المعالجة؛ لا تسقط الأعمدة أو تعدّل جدول migrations يدويًا.
أي فشل هنا يبقي الموقع مغلقًا حتى قرار التراجع المشروط أدناه.

## 6. تبديل الحاويات وفحوص التشغيل — تغيير الإصدار واستئناف الإنتاج

```bash
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 app
# إعادة nginx ضرورية أيضًا لتجديد عنوان app الداخلي وخدمة ملفات Vue المطابقة.
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 nginx
dc_new exec -T app php artisan migrate:status --no-interaction
dc_new exec -T app php artisan route:list --path=api/v1/driver/orders --no-interaction
dc_new exec -T --user www-data app php artisan up --no-interaction
curl --fail --silent --show-error "$PUBLIC_URL/up"
curl --fail --silent --show-error -o /dev/null "$PUBLIC_URL/driver/orders"
curl --fail --silent --show-error -o /dev/null "$PUBLIC_URL/admin/delivery-exceptions"
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 queue scheduler
dc_new ps
dc_new exec -T app php artisan queue:failed --no-interaction
```

فحوص curl تثبت النقل وSPA فقط؛ لا تثبت الصلاحيات أو نجاح إجراء. أكمل من جلسات
مخوّلة في المتصفح، دون نسخ tokens أو بيانات العملاء:

1. مصدر JS المحمل يتبع أثر Nginx الجديد وindex.html لا يُخزن طويلًا.
2. المندوب يرى مسؤول تأكيد الشبكة ولا يستطيع تأكيدها، ويرى طلب التدخل.
3. الكاشير يرى تأكيد الشبكة والتحقق الصريح، ولا يرى حسم عهدة أو إلغاء استثنائي.
4. الإدارة ترى SH-0002 والطلبات السابقة والإجراءات الموافقة لصلاحياتها.
5. العميل يرى الحالة العامة دون سبب داخلي أو بيانات المندوب المالية.
6. راقب الأخطاء والـqueue داخليًا دون تصدير raw logs أو payloads.

لا تُنشئ طلبًا أو دفعًا وهميًا في الإنتاج للاختبار. معالجة SH-0002 عملية تشغيل
منفصلة: تحقق فعلي من نجاح 57 SAR على الشبكة ثم تأكيد المخوّل، ثم التسليم الفعلي
من المندوب والإكمال من الكاشير؛ أو إجراء استثناء مطابق للواقع. لا ينفذ النشر ذلك.

## 7. التراجع

### قبل Migration أو بعد نجاحها دون استعمال القيم الجديدة

بعد Migration، شغّل delivery-postflight.sql واقرأ بوابات التوافق الثلاث. لا ترجع
الصورة القديمة إلا إذا كانت كلها صفرًا. schema الإضافية تُترك في مكانها، ولا تعكس
Migration. إذا فشلت Migration قبل إنشاء الأعمدة فلا تشغل postflight؛ افحص preflight
واستخدم الصورة القديمة فقط إن بقيت الحالات والمصادر ضمن قيم الإصدار القديم.

```bash
# إذا أعيد فتح الموقع: أعد نافذة التوقف وأوقف استقبال الطلبات والـworkers أولًا.
ROLLBACK_APP_CID=$(dc_new ps -aq app)
if test -n "$ROLLBACK_APP_CID" && test "$(docker inspect --format '{{.State.Running}}' "$ROLLBACK_APP_CID")" = true; then
  dc_new exec -T --user www-data app php artisan down --retry=60 --no-interaction
fi
dc_new stop -t 90 nginx
dc_new stop -t 90 queue scheduler
dc_new stop -t 90 app
export SHISHLI_RELEASE_APP_IMAGE="shishli-app:pre-$RELEASE_ID"
export SHISHLI_RELEASE_NGINX_IMAGE="shishli-nginx:pre-$RELEASE_ID"
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 app
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 nginx
dc_new exec -T --user www-data app php artisan up --no-interaction
curl --fail --silent --show-error "$PUBLIC_URL/up"
dc_new up -d --no-deps --force-recreate --wait --wait-timeout 120 queue scheduler
```

إذا app الجديد لا يبدأ، تجاوز أمر down الذي يتطلبه، وأبقِ nginx/queue/scheduler
متوقفة؛ maintenance file من النافذة الأصلية موجود في Storage المشترك. لا تستعمل
up أو إعادة الـworkers قبل التحقق من شروط التوافق السابقة.

### بعد وجود returning / returned / released أو source=delivery_exception

**ممنوع إرجاع الصورة القديمة مباشرة**؛ قد تفشل enum casts وتفسد تعريف العهدة.
أبقِ الصيانة واستخدم forward fix أو صورة تراجع تدعم الحالات الجديدة. الاستعادة
الكاملة للـDB وStorage ليست تراجعًا برمجيًا عاديًا: تمحو طلبات/تحصيلات لاحقة للنسخة؛
تحتاج موافقة حادث منفصلة ومصالحة المدفوعات الخارجية. لذلك لا توجد هنا أوامر import
إلى قاعدة الإنتاج. أعد أخذ backup لحالة الحادث قبل أي معالجة، ولا تعِد تشغيل أحداث
الدفع أو Redis آليًا. لا تنفذ migrate:rollback حتى إن كان ledger فارغًا.

## 8. تنظيف الاختبار فقط — يمحو بيانات اختبار/نسخة الاستعادة على خادمه المعزول

```bash
test "$TEST_PROJECT" = "shishli-delivery-test-$RELEASE_ID"
tc down
unset DELIVERY_TEST_ROOT_PASSWORD
```

tmpfs يختفي مع حاوية الاختبار، بما فيه نسخة الاستعادة الخاصة. لا تستخدم down -v
أو prune أو تغيير Volumes mysql/redis/storage. احتفظ بالنسخ المشفرة والتقارير والصور
القديمة وفق سياسة الاحتفاظ. احفظ أسماء الصور الجديدة في سجل النشر كي تستخدم نفس
overlay في إعادة التشغيل المقبلة؛ compose.yaml وحده ما زال يشير إلى :local.
