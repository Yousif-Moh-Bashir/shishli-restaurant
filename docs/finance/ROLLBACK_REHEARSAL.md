# تمرين العودة إلى إصدار Laravel السابق — معزول فقط

هذه خطوات اختبار عملية قابلة للتنفيذ مستقبلًا وليست نتيجة ناجحة. لم تُنفذ في جولة الجرد: تعذر الوصول إلى إعدادات SSH فتوقف الجرد، ولم تُعرف صورة الإصدار السابق الفعلية. لا يكفي تبديل predicate في اختبار وحدة لإثبات توافق صورة إصدار كامل. المقصود صورة تطبيق Laravel السابقة، لا downgrade عشوائيًا لإصدار framework/Composer.

المطلوب: Docker محلي مصرح به وصورة قديمة مطابقة لـimage digest الإنتاج المثبت من الجرد، وصورة الاختبار الحالية. لا سحب صورة مجهولة أو نسخ بيانات الإنتاج. جميع البيانات fixtures اصطناعية، وDB في tmpfs وشبكة internal بلا منافذ DB. لا تستخدم Compose المطعم أو شبكة/Storage الإنتاج.

## إعداد التجربة

من جذر candidate المراجع في Bash محلي، لا على VPS:

```bash
set -euo pipefail
umask 077
: "${OLD_APP_IMAGE:?locally available verified previous app image digest}"
docker image inspect "$OLD_APP_IMAGE" --format '{{.Id}}' # لا inspect كاملًا.
REHEARSAL_ROOT=$(pwd -P)
REHEARSAL_DIR=$(mktemp -d)
export REHEARSAL_PRIVATE_DIR="$REHEARSAL_DIR/private"
mkdir -m 700 "$REHEARSAL_PRIVATE_DIR"
export REHEARSAL_APP_IMAGE=shishli-finance-runtime-app:test
# لا توجد بيئة سابقة بهذا الاسم؛ إن وجدت توقف ولا تمسحها تلقائيًا.
test -z "$(docker ps -aq --filter label=com.docker.compose.project=shishli-finance-rollback)"
cat > "$REHEARSAL_DIR/override.yaml" <<'YAML'
services:
  app:
    image: ${REHEARSAL_APP_IMAGE:?}
    command: [php-fpm]
    volumes: ['${REHEARSAL_PRIVATE_DIR:?}:/rehearsal']
networks:
  default:
    internal: true
YAML
rc() { docker compose --env-file /dev/null --project-directory "$REHEARSAL_ROOT/docker" \
  -p shishli-finance-rollback -f "$REHEARSAL_ROOT/docker/compose.finance-runtime-test.yaml" \
  -f "$REHEARSAL_DIR/override.yaml" "$@"; }
rc config --quiet
rc build app customer-web finance-web
rc up -d --wait finance-mysql app
rc exec -T app php tests/Support/finance-runtime-setup.php
rc up -d customer-web finance-web
```

المنافذ18099/18100 يجب أن تكون حرة. لا تشغّل test:finance:live على هذه التجربة؛ بياناتها مصممة لهذا التمرين وليست الدورة المعتادة. لا تشغّل setup على الصورة القديمة أو بعد التبديل؛ سيغيّر مخطط الاختبار الذي نريد إبقاءه.

إنشاء ثلاث هويات اختبار وتوكنات في ملفات curl خاصة، دون طباعة القيم:

```bash
rc exec -T -e REHEARSAL_UID="$(id -u)" -e REHEARSAL_GID="$(id -g)" app php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.connections.mysql.host') !== 'finance-mysql'
    || config('database.connections.mysql.database') !== 'shishli_finance_test'
    || config('database.connections.mysql.url')) { throw new RuntimeException('Not isolated'); }
Spatie\Permission\Models\Role::findOrCreate('cashier', 'web');
foreach (['finance-only' => ['finance_manager'], 'mixed' => ['finance_manager', 'cashier'], 'staff' => ['cashier']] as $key => $roles) {
    $user = App\Models\User::create(['name' => 'Rollback fixture', 'email' => $key.'@rollback.example.test',
        'password' => Illuminate\Support\Str::random(40), 'is_active' => true]);
    $user->assignRole($roles);
    foreach (['finance' => ['finance'], 'api' => ['*']] as $name => $abilities) {
        $token = $user->createToken($name, $abilities, $name === 'finance' ? now()->addHours(8) : null)->plainTextToken;
        $path = '/rehearsal/'.$key.'-'.$name.'.curl';
        file_put_contents($path, 'header = "Authorization: Bearer '.$token.'"'."\n".'header = "Accept: application/json"'."\n");
        // ملكية ملفات fixtures المحلية فقط، لا صلاحيات VPS أو ملفات الإنتاج.
        if (!chown($path, (int) getenv('REHEARSAL_UID')) || !chgrp($path, (int) getenv('REHEARSAL_GID')) || !chmod($path, 0600)) {
            throw new RuntimeException('Cannot prepare private local fixture file; stop rehearsal');
        }
    }
}
echo "Synthetic rollback fixtures ready; tokens withheld.\n";
PHP
status() { curl -sS --config "$REHEARSAL_PRIVATE_DIR/$1.curl" -o /dev/null -w '%{http_code}' "http://127.0.0.1:18100/api/v1/admin/me"; }
test "$(status finance-only-finance)" = 403
test "$(status finance-only-api)" = 403
test "$(status mixed-finance)" = 403
test "$(status staff-api)" = 200
```

سجّل نتائج baseline دون أجسام الاستجابات أو التوكنات. تأكد أيضًا أن المالية تعمل بالتوكن المالي فقط على18099 وHost المالي، وأن التوكن العام مرفوض؛ اختبارات hardening الحالية تغطي هذه الحالة.

## تجربة العودة غير المعالجة — إثبات الخطر، ممنوعة في الإنتاج

```bash
DB_CID_BEFORE=$(rc ps -q finance-mysql)
export REHEARSAL_APP_IMAGE="$OLD_APP_IMAGE"
rc up -d --no-deps --no-build --force-recreate --wait app
# إعادة customer-web لتجديد عنوان FPM، مع بقاء صورة Nginx المقواة.
rc up -d --no-deps --no-build --force-recreate customer-web
test "$(rc ps -q finance-mysql)" = "$DB_CID_BEFORE"
# للإصدار القديم الذي يكتفي باستثناء driver.* في AdminAccessService نتوقع إثبات التجاوز:
test "$(status finance-only-finance)" = 200
test "$(status finance-only-api)" = 200
test "$(status mixed-finance)" = 200
test "$(status staff-api)" = 200
```

إذا كان الإصدار السابق الفعلي يمنعها بالفعل، سجّل 401/403 وراجع مصدره وصلاحية التوكن بدل الادعاء بإعادة إنتاج الخطر. لا تعتمد توقع200 إلا إذا طابق المصدر القديم الذي ثبتت مراجعته. هذه حالة سلبية متعمدة داخل المعزل وليست نتيجة مقبولة لنشر/تراجع إنتاج.

## تنظيف منح المالية قبل العودة الآمنة

أعد الصورة الحالية لتنفيذ التنظيف على الهويات الاصطناعية فقط، ولا تحذف roles/permissions عالميًا:

```bash
export REHEARSAL_APP_IMAGE=shishli-finance-runtime-app:test
rc up -d --no-deps --no-build --force-recreate --wait app
rc exec -T app php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.connections.mysql.host') !== 'finance-mysql'
    || config('database.connections.mysql.database') !== 'shishli_finance_test'
    || config('database.connections.mysql.url')) { throw new RuntimeException('Not isolated'); }
Illuminate\Support\Facades\DB::transaction(function () {
    foreach (['finance-only', 'mixed'] as $key) {
        $user = App\Models\User::where('email', $key.'@rollback.example.test')->firstOrFail();
        $user->tokens()->delete(); // يشمل التوكن العام للحساب المالي أيضًا؛ staff لا يُمس.
        foreach ($user->roles as $role) {
            if (str_starts_with($role->name, 'finance_')) { $user->removeRole($role); }
        }
        foreach ($user->permissions as $permission) {
            if (str_starts_with($permission->name, 'finance.')) { $user->revokePermissionTo($permission); }
        }
    }
});
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
PHP
export REHEARSAL_APP_IMAGE="$OLD_APP_IMAGE"
rc up -d --no-deps --no-build --force-recreate --wait app
rc up -d --no-deps --no-build --force-recreate customer-web
test "$(status finance-only-finance)" = 401
test "$(status finance-only-api)" = 401
test "$(status mixed-finance)" = 401
test "$(status staff-api)" = 200
test "$(rc ps -q finance-mysql)" = "$DB_CID_BEFORE"
rc stop
```

أكمل التحقق على المخطط نفسه: تسجيل دخول عام جديد للحساب المالي الصرف يجب ألا يعطيه وصول الإدارة؛ الحساب المشترك يحتفظ بدور cashier فقط ويمكنه الدخول بصلاحياته الأصلية بتوكن جديد؛ لا finance.* grants مباشرة أو عبر دور مخصص متبقٍ. اختبر العميل والمندوب والإدارة بالصور القديمة، وسلامة قيود Finance والبيانات وسجل التدقيق دون migration عكسي. في الإنتاج لا يكفي فحص بادئة اسم الدور؛ الأدوار المخصصة قد تحمل finance.* وتحتاج جرد grants فعليًا وخطة لكل حساب قبل السماح بالتراجع. إبطال توكنات الحساب المشترك يخرجه من جلساته ويحتاج موافقة أثره.

بعد اعتماد نتائج التمرين، أزل حاوياته ومساحته الخاصة بالاسم بعد التحقق من المسارات، وفق إجراء إزالة مؤقتات الخطة؛ لا تلمس مشروع المطعم أو صوره/أحجامه. احفظ النتيجة: صورة قديمة/جديدة digest، DB metadata، حالات HTTP قبل/بعد، وبقاء grants المطعم وتوكن staff. **وجود هذه الخطوات لا يعني نجاح التمرين ما لم تنفذ وتسجل الأدلة.**
