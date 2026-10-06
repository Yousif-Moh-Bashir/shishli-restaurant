# تقرير ربط الإعدادات وتشغيل المطعم

النطاق: Settings، ساعات الفروع، الإغلاقات المؤقتة، الحالة التشغيلية، وتكاملها مع الموقع والسلة وتأكيد الطلب. لا تعديل لمصدر Laravel أو HTML القديم، ولا تنفيذ CMS أو التدقيق النهائي للنظام.

## 1. ملفات الباك التي فُحصت

- `routes/api.php`، `config/business.php`، `config/filesystems.php` و`database/seeders/RolePermissionSeeder.php` و`RestaurantSettingsSeeder.php`.
- Controllers: `Admin/RestaurantSettingsController`، `PublicConfigurationController`، `Admin/BranchBusinessHoursController`، `BranchBusinessHoursController`، `Admin/BranchClosureController`، `BranchStatusController`، وControllers الفروع العامة والإدارية.
- Requests: `UpdateSettingsRequest`، `SettingAuditRequest`، `ReplaceBusinessHoursRequest`، `StoreBranchClosureRequest`، `UpdateBranchClosureRequest`، `UpdateBranchRequest`، `CheckoutRequest`.
- Resources: `RestaurantSettingResource`، `PublicConfigurationResource`، `SettingChangeLogResource`، `BranchBusinessHoursResource`، `BranchClosureResource`، `BranchAvailabilityResource`، `BranchResource`، `AdminBranchResource`، `CartResource`.
- Models: `RestaurantSetting`، `SettingChangeLog`، `BranchBusinessHour`، `BranchClosure`، وعلاقات `Branch`.
- Services: `RestaurantSettingDefinition`، `RestaurantSettingsService`، `BranchScheduleService`، `BranchAvailabilityService`، `BranchAvailabilityData`، `BusinessHoursOverlapValidator`، `CartStateService`، `DeliveryService`.
- Actions: `UpdateRestaurantSettingsAction`، `ReplaceBusinessHoursAction`، `ManageBranchClosureAction`، `CreateCartAction`، `PlaceOrderAction`.
- Enums: `SettingType`، `DayOfWeek`، `BranchAvailabilityIssueCode`، وعقود أنواع وتوقيت الطلب.
- Migration: `2026_10_03_002636_create_restaurant_settings_and_operational_schedules.php`.
- Tests: `RestaurantSettingsTest`، `BranchScheduleTest`، `OperationalAvailabilityTest`؛ ومراجعة عقود Cart/Checkout وتكاملاتها الموجودة. لم يظهر Policy يقيّد هذه العمليات بفرع المدير، أو Event/Listener مستقل يغيّر عقد التشغيل.
- المرجع البصري: `Şişli/dashboard/settings.html` والمكونات الحالية للألوان والخط والكروت والأيقونات والجداول والمودالات.

## 2–7. معمارية Settings والعقود والصلاحيات

**2. المعمارية:** جدول `restaurant_settings` بقيم JSON وبيانات type/group. Registry ثابت يحدد المفاتيح والأنواع والتحقق والظهور العام. توجد ست مجموعات و26 مفتاحًا؛ الفروع والساعات والإغلاقات Domains منفصلة. لا يسمح Registry لمفتاح مجهول أو metadata داخل قاعدة البيانات بتوسيع الظهور العام.

**3. Public endpoint:** `GET /api/v1/settings/public`، بدون Auth، باستجابة متداخلة حسب المجموعة، وبـ`private, no-store`.

**4. Admin endpoints:**

| العملية | المسار بعد `/api/v1` | الصلاحية |
|---|---|---|
| قراءة الإعدادات | `GET /admin/settings` | `settings.view` |
| قراءة المجموعات | `GET /admin/settings/groups` | `settings.view` |
| تعديل جزئي | `PATCH /admin/settings`، body: `{settings:{"literal.dotted.key":value}}` | `settings.update` |
| سجل التغييرات | `GET /admin/settings/audit` | `settings.audit.view` |

**5–6. الحقول الإدارية والعامة:**

| المجموعة | المفاتيح الفعلية |
|---|---|
| general | `restaurant.name`, `restaurant.name_en`, `restaurant.currency`, `restaurant.timezone`, `restaurant.country`, `restaurant.city` |
| contact | `restaurant.phone`, `restaurant.whatsapp`, `restaurant.email` |
| branding | `branding.logo_path`, `branding.primary_color` |
| orders | `orders.enabled`, `orders.minimum_amount`, `orders.maximum_items_per_order`, `orders.allow_guest_checkout`, `orders.guest_tracking_token_days`, `orders.allow_scheduled_orders`, `orders.max_scheduling_days`, `orders.maintenance_mode`, `orders.maintenance_message` |
| pickup | `pickup.enabled`, `pickup.minimum_amount`, `pickup.default_preparation_minutes` |
| delivery | `delivery.enabled`, `delivery.default_preparation_minutes`, `delivery.require_coordinates` |

كل هذه المفاتيح تظهر للموظف المخوّل. العامة تستثني دائمًا مدة رمز تتبع الزائر، وتعرض رسالة الصيانة فقط أثناء تفعيل الصيانة: 24 مفتاحًا عادة، و25 أثناء الصيانة. العملة الوحيدة المسموحة SAR. لا حقول لإعدادات الدفع أو SMTP أو SMS أو أسرار أو روابط اجتماعية أو خريطة أو عنوان كامل ضمن Settings.

**7. الصلاحيات:** التعديل يعتمد `settings.update`، وليس `settings.manage` القديم. المدير الحالي يقرأ الإعدادات والسجل ولا يعدّل الإعدادات العامة. تشغيل الفرع يعتمد `branches.update`، والساعات/الإغلاقات تعتمد صلاحياتها المستقلة. الأدوار ليست مصدر قرارات Vue؛ الصلاحيات الفعلية تأتي من `/admin/me`.

## 8–17. الساعات والاستثناءات

| رقم | النتيجة الفعلية |
|---|---|
| 8 | `GET/PUT /admin/branches/{uuid}/business-hours`، و`GET /branches/{uuid}/business-hours` للعامة. صلاحيات القراءة/التعديل: `branches.hours.view/update`. |
| 9 | القراءة: `{timezone,days:[{day_of_week,day_name,periods:[{opens_at,closes_at}]}]}`. الكتابة: `{hours:[{day_of_week,opens_at,closes_at}]}`، بصيغة H:i وحد أقصى 100 فترة. |
| 10 | Per Branch فقط؛ لا جدول ساعات Global. PUT يستبدل الأسبوع كله. اليوم بلا فترة مغلق، والأسبوع الفارغ يغلق جميع الأيام. |
| 11 | ISO: الاثنين=1 إلى الأحد=7. الواجهة تستخدم هذا العقد. |
| 12 | `18:00 → 03:00` يمتد لليوم التالي، وينتمي ليوم الفتح. التساوي ممنوع، والفترات المتجاورة مسموحة. |
| 13 | المنطقة الزمنية من `restaurant.timezone`، الافتراضية `Asia/Riyadh`. ساعات الأسبوع لا تعتمد توقيت الجهاز. الإغلاقات تُدخل صراحة بتوقيت UTC؛ الطلب المجدول يعرض أن الإدخال بتوقيت جهاز العميل ويُرسل ISO UTC. |
| 14 | `GET/POST /admin/branches/{uuid}/closures`، و`PATCH/DELETE /admin/branches/{uuid}/closures/{closureUuid}`. لا endpoint مستقل لـschedule exceptions أو special hours. |
| 15 | الإغلاق: `starts_at`, `ends_at`, `reason`, `is_active`؛ لا نوع Holiday أو Special Opening. التاريخان ISO مع timezone، والنهاية بعد البداية. السبب إداري. |
| 16 | الإغلاق النشط يتقدم على الجدول؛ البداية مشمولة والنهاية مستثناة. الموعد القادم يراعي الإغلاقات، بما فيها الإغلاقات المتداخلة. |
| 17 | تداخل فترات الأسبوع، بما فيها الأحد/الاثنين وبعد منتصف الليل، يُرفض بـ422. تداخل الإغلاقات مسموح، وليس 409. ملكية الإغلاق لفرع آخر تُرفض بـ404. |

## 18–33. الحالة التشغيلية والكاش وتكامل العميل

| رقم | النتيجة والتنفيذ |
|---|---|
| 18 | حقول الفرع القابلة للتعديل: `is_active`, `accepts_orders`, `supports_pickup`, `supports_delivery`. واجهة التشغيل تعدّل الثلاثة الأخيرة، و`is_active` يبقى في محرر الفرع الموجود. |
| 19 | `is_active` نشاط إداري؛ `is_open` محسوب من الجدول والإغلاقات؛ `accepts_orders` في Resource الفرع هو المفتاح اليدوي، وفي Resource availability هو القبول الفعلي بعد تطبيق جميع القيود. |
| 20 | `BranchAvailabilityService`: صيانة الطلبات، ثم إيقاف المطعم، ثم الفرع غير النشط، ثم مفتاح الفرع، ثم الإغلاق المؤقت، ثم الساعات، ثم تفعيل نوع الطلب عالميًا، ثم دعم الفرع للنوع. |
| 21 | الحساب في `BranchScheduleService` حسب المنطقة الزمنية وفترات اليوم واليوم السابق للإغلاق الليلي. Vue لا تحسب مفتوح/مغلق ولا تعرض Toggle لتلك النتيجة. |
| 22 | `next_opening_at` من الخادم، UTC، ببحث أقصاه 14 يومًا. يُعرض عند وجوده ويُنسّق فقط بالمنطقة الزمنية المعلنة. لا يُخترع موعد عند null. |
| 23 | `orders.maintenance_mode/message` مدعومان. لا يُغلق الموقع؛ القائمة والسلة والطلبات الحالية تظل وفق عقد الباك. |
| 24 | إيقاف عام يدوي بـ`orders.enabled`، وإيقاف فرع يدوي بـ`accepts_orders`، وإغلاق محدد الفترة بـclosures. لا حقل مستقل لتعليق عالمي ينتهي تلقائيًا. |
| 25 | الاستلام يعتمد المفتاح العام ودعم الفرع والحالة الفعلية. الواجهة تخفي الاختيار غير المتاح؛ المستقبل المجدول يبقى قرار الخادم عند الموعد المطلوب. |
| 26 | التوصيل يعتمد المفتاح العام ودعم الفرع أيضًا؛ وجود Zone وحده لا يكفي. Quote يراجع قواعد الرسوم والعنوان والمنطقة، وليس ضمانًا نهائيًا للتشغيل في موعد المستقبل. Checkout يعيد التحقق. |
| 27 | الحالة العامة من `GET /branches/{uuid}/status`: `is_active`, `is_open`, `accepts_orders`, `pickup`, `delivery`, `reason`, `next_opening_at`. رسائل reason العامة ورسالة الصيانة فقط تُعرض للعميل؛ سبب الإغلاق الإداري لا يُعرض. |
| 28 | المنيو يظل قابلًا للتصفح عند الإغلاق إذا سمح الباك. محدد الفرع يعرض الحالة، ويسمح بالتصفح عندما تُرفض محاولة تحديث سلة فارغة؛ الإضافة تستمر وفق قبول API وتزامن فرع السلة. |
| 29 | السلة لا تُمسح بسبب توقف التشغيل. تُعرض issues وavailability الحقيقية. رابط المراجعة يتيح الوصول إلى الجدولة الممكنة، والإرسال نفسه يظل مقيدًا. |
| 30 | قبل إرسال جديد: تحديث Public Settings وStatus وحقول الفرع. يُوقف الإرسال عند فشل التحقق أو تغير طريقة الاستلام، ويطلب مراجعة الاختيار بدل التحويل الصامت إلى Pickup. محاولات استرجاع نتيجة طلب معلّق تحتفظ بمسارها الأصلي. |
| 31 | الباك يعيد التحقق داخل Action قبل وبعد إعادة التسعير. عند رفض422: عرض الخطأ وbusiness issues، وتحديث السلة والإعدادات والحالة، دون إنشاء طلب وهمي أو حذف المنتجات. |
| 32 | Backend snapshot عبر `Cache::rememberForever('restaurant.settings.v1')` مع lock؛ لا memoization طويلة داخل instance. الحالة التشغيلية تُحسب في الطلب. Public settings/status يرسلان no-store. |
| 33 | الحفظ داخل transaction يبطل الكاش وبعد commit. Vue يعيد القراءة بعد الحفظ. مشاركة البيانات العامة قصيرة لمدة15 ثانية، وForce refresh ينتظر القراءة الجارية ثم يجلب قراءة جديدة. Refresh يدوي وعند العودة للتبويب، وقبل التأكيد؛ لا polling عدواني ولا WebSocket. |

## 34–40. تغييرات Vue والتغطية

**34. الملفات الجديدة:**

- `src/api/settings.api.js`, `businessHours.api.js`, `operations.api.js`.
- `src/constants/settings.js`؛ `src/stores/publicSettings.js`, `operations.js`.
- `src/composables/useRestaurantInfo.js`, `useUnsavedForm.js`.
- `src/components/admin/operations/{BranchOperations,BusinessHoursPanel,ClosuresPanel,SettingsAudit}.vue`.
- `src/components/site/{PublicOperationStatus,PublicBusinessHours}.vue`.
- `src/assets/css/settings.css`؛ `tests/settings-operations.browser.mjs`.

**35. الملفات المعدلة:** SettingsView، BranchDetailsView، CheckoutView، ContactView، MenuView، SiteLayout، SiteHeader، SiteFooter، BranchSelector، CartItems، CartSummary، CatalogEditor، AdminModal، admin.css، main.js، router/index.js، adminNavigation.js، branches.api.js، checkout.api.js، stores/branch.js، stores/cart.js، data/adminDashboard.js، config/restaurant.js.

**36. Routes/Tabs:** نفس `/admin/settings`، بصلاحيات القراءة المناسبة. ستة أقسام Settings فعلية، تشغيل الفروع، وسجل التغييرات. إدارة الفرع تتضمن قسم التشغيل والساعات والإغلاقات. لم تُضف صفحات CMS.

**37. Missing UI:** Settings كانت معاينة بستة حقول غير مطابقة للعقد؛ لا محرر أسبوع أو إغلاقات أو سجل تغييرات أو حالة فرع عامة موحدة أو تحديث تشغيلي قبل الإرسال؛ هاتف وساعات عامة ثابتة.

**38. Implemented:** 26 إعدادًا معروفًا مع typed controls، nullable values، تغييرات جزئية، حفظ فعلي و422، وتأكيد للتغييرات الحساسة؛ مسودات لا تضيع بين الأقسام وتحذير عند المغادرة. محرر أسبوع متعدد الفترات، CRUD للإغلاقات، تشغيل الفرع، سجل paginated بفلاتر المفتاح والتواريخ وعدد النتائج والمنفذ المختار من السجل. حالة عامة وموعد فتح وساعات حقيقية، وبيانات التواصل من Public Settings. جميع المودالات تحافظ على التمرير الداخلي والمسافة حول شريطه؛ IDs أصبحت فريدة عند وجود عدة مودالات.

**39. Backend-only:** locks/cache keys/audit timestamps/UUIDs لا تُحرّر؛ لا أسرار أو مفاتيح APIs؛ computed availability لا تُحرّر. تغيير السبب الإداري لا ينشره للعامة. Branding path/color يُحفظان ويُقرآن حسب العقد؛ لم يُخترع URL للشعار أو رفع وسائط، ولم تُغيّر الهوية البصرية تلقائيًا من هذه المرحلة.

**40. Demo removed:** صفحة إعدادات المعاينة و`useAdminSettingsPreview.js` وSettings hydration من `adminDashboard.js` والساعات العامة الوهمية. محتوى CMS القديم والعناوين/الخريطة/الروابط الاجتماعية خارج عقد Settings بقي خارج النطاق.

## 41–56. نتائج التحقق والحدود

تشغيل الباك: `php artisan test --compact --filter='RestaurantSettingsTest|BranchScheduleTest|OperationalAvailabilityTest'` على SQLite in-memory: **123 passed، 495 assertions**. لم يُعدّل أي ملف مصدر Laravel.

| رقم | الفحص | النتيجة |
|---|---|---|
| 41 | Global Settings | تحميل وتعديل و422 وتحديث الكاش فعليًا من UI/API؛ بقاء المسودة عند تغيير القسم. |
| 42 | Business Hours | API حقيقي يستبدل الأسبوع، وواجهة7 أيام؛ فتح كامل/أسبوع فارغ. |
| 43 | Overnight | حفظ18:00–03:00 وقراءة03:00 فعليًا؛ اختبارات PHP تؤكد الحدود واليوم التالي وDST. |
| 44 | Closure | إنشاء وتعطيل وحذف فعلي، وتقدم الإغلاق على الساعات؛ UI مودال متجاوب. |
| 45 | Open/Closed | Status API يعكس الأسبوع المفتوح والفارغ والإغلاق فورًا. |
| 46 | Accept Orders | إيقاف/إعادة تشغيل المطعم والفرع فعليًا؛ API هي المصدر. |
| 47 | Pickup/Delivery | المفاتيح العامة OFF/ON ورموز السبب الفعلية؛ PHP يغطي دعم الفرع والحدود والطلبات. |
| 48 | Maintenance | رسالة عامة تظهر أثناء الصيانة فقط وتختفي بعد إلغائها؛ اختبار فعلي. |
| 49 | Checkout Race | فحص إعداد تغيّر قبل preflight، وفحص فرع يتوقف بعد preflight وقبل POST: رفض422، تحديث الواجهة وبقاء السلة. PHP يغطي تغيّر الإغلاق أثناء إعادة التسعير وعبور لحظة الإغلاق. |
| 50 | Timezone | PHP يغطي الرياض، UTC، منتصف الليل، الجمعة/السبت وDST نيويورك؛ UI يعلن UTC للإغلاقات ويستخدم timezone القادمة من الباك للساعات. |
| 51 | Permissions | مدير فعلي: قراءة فقط للإعدادات وPATCH403. PHP يغطي401/403 والصلاحية المستقلة لكل endpoint. لا branch-scoped manager في العقد الحالي. |
| 52 | Cache invalidation | API العامة تعكس الاسم والمفاتيح الجديدة مباشرة بعد PATCH؛ PHP يغطي warmed cache وrollback. |
| 53 | Backend issues/support gaps | لم تُكتشف مشكلة تمنع هذه العمليات. Special-opening exceptions/Holiday enums وتعليق عالمي محدد النهاية غير مدعومة. Branding تعيد path لا canonical logo URL، وAudit يعيد actor UUID دون الاسم؛ لم تُخترع بيانات أو endpoints. |
| 54 | Build | `npm run build` ناجح. تبقى التحذيرات الموجودة عن حجم الحزمة وmixed imports؛ ليست أخطاء بناء. |
| 55 | Console | اختبارات المتصفح لم ترصد Vue/runtime diagnostics. 422/500 المتوقعان في الفحص منفصلان عن أخطاء غير متوقعة. |
| 56 | Remaining | لا ميزة تشغيل مدعومة تحتاج تنفيذًا إضافيًا في هذه المرحلة. الحدود غير المدعومة أعلاه موثقة. إعدادات الاختبار وساعات الفرع ومفاتيحه تُعاد إلى الأصل؛ سجل التدقيق يحتفظ بالتغييرات الحقيقية، وسلة الاختبار تُفرغ دون إنشاء Order ناجح. |

اختبار المتصفح: **17 سيناريو**، منها16 live API/UI وtransport fixture واحد لخطأ500 وإعادة المحاولة، مع3 عروض متجاوبة ومقاسين للمودال و0 console diagnostics. أُثبتت استعادة الإعدادات والساعات ومفاتيح الفرع بالمقارنة مع اللقطات الأصلية. تقرير التنفيذ التفصيلي في `.reports-check/settings-operations/last-run.json`، والصور لثلاثة عروض في المجلد نفسه. الفحص لا يزعم أنه تدقيق كامل لبقية وحدات النظام.

## BACKEND → FRONTEND COVERAGE

| Backend feature | Frontend coverage |
|---|---|
| Settings registry/type/groups/partial patch | ستة أقسام،26 حقلًا، حفظ جزئي و422 وتأكيد وdirty state |
| Immutable audit + supported filters/pagination | سجل للعرض فقط، تصفية وتصفح، اختيار تغييرات المنفّذ من السجل |
| Weekly branch hours/multiple shifts/overnight/overlap | محرر أسبوع، فترات متعددة، أيام مغلقة، أخطاء الباك وحفظ مع تأكيد |
| Closures CRUD/activation/ownership | قائمة ومودالات إضافة/تعديل/تعطيل/حذف وتواريخ UTC، تحديث الحالة بعد النجاح |
| Manual branch operations/capabilities | مفاتيح استقبال الطلبات ودعم طرق الاستلام مع تأكيد؛ `is_active` في محرر الفرع |
| Server availability/next opening/reasons | عرض إداري وعام ومحدد الفروع وساعات الموقع؛ لا state machine في Vue |
| Public configuration/contact | Public store منفصل، هاتف/واتساب/اسم/بريد فعلي، منع استخدام admin endpoint للعامة |
| Ordering disabled/maintenance/pickup/delivery | إعدادات حقيقية، رسائل عامة، بقاء التصفح، وبقاء السلة، وCheckout يتحقق مجددًا |
| Internal locks/cache/secrets/computed fields | لا editors أو cache buttons أو أسرار أو toggles لحالة الفتح المحسوبة |

## NEXT MODULE READINESS

مرحلة Settings/Operations جاهزة للانتقال إلى CMS من ناحية هذه العقود والتكامل. المرحلة التالية يمكن أن تفحص Pages، Page Sections، Banners، Gallery، Media Uploads، SEO، Contact Messages وPublic Home Composite API؛ يلزم تدقيقها بعقودها عند بدء المهمة. لم يبدأ أي تنفيذ CMS أو تدقيق نهائي كامل للنظام في هذه المهمة.
