# Order Lifecycle / Kitchen / Tracking — تقرير التنفيذ

التنفيذ بتاريخ 2026-10-04. Laravel هو مصدر الحالات والصلاحيات والأهلية والأسعار؛ Vue يعرض الموارد وينفذ إجراءات HTTP. لم تُعدّل ملفات Backend أو Old HTML، ولم تبدأ مرحلة Payments. تغييرات Backend الموجودة مسبقًا في مساحة العمل تُركت كما هي.

## التقرير المطلوب — 51 نقطة

1. **Backend files inspected:** `routes/api.php`؛ Controllers: `Admin/OrderController`, `Admin/OrderOperationController`, `Admin/OrderHistoryController`, `KitchenOrderController`, `OrderController`, `OrderCancellationController`, `GuestOrderController`, `OrderTrackingController`؛ Requests: `Admin/AdminOrdersRequest`, `ListOrdersRequest`, `KitchenOrdersRequest`, `OrderOperationRequest`, `CancelOrderRequest`, `TrackOrderRequest`؛ Resources: `AdminOrderResource`, `OrderResource`, `OrderItemResource`, `OrderItemOptionResource`, `OrderAddressResource`, `KitchenOrderResource`, `OrderStatusHistoryResource`, `OrderTrackingResource`, `OrderTrackingTimelineResource`؛ Models: `Order`, `OrderStatusHistory` وعلاقات العناصر والعنوان؛ Actions: `TransitionOrderStatusAction`, `CancelOrderAction`, `PlaceOrderAction`؛ Services: `OrderStateMachine`, `OrderOperationAuthorization`, `AdminOrderActions`, `AdminOrderQuery`, `AdminOrderFilters`, `OrderPaymentPolicy`, `GuestOrderAccess`, `OrderTrackingAccessService`, `OrderTrackingService`, `OrderTrackingData`, `OrderEstimateService`, `OrderStatusPresenter`, `OrderTimingService`؛ Enums: `OrderStatus`, `OrderType`, `OrderStatusSource`, `OrderTiming`؛ `RolePermissionSeeder`؛ `OrderStatusChanged` و`QueueOrderStatusChangedNotifications`؛ migrations سجل الحالات وتواريخ الانتقالات وأمان الضيف والتقديرات. راجعت اختبارات Lifecycle/Validation/Transaction/Cancellation/Kitchen/Tracking/Security/Estimates/Performance/AdminOperations/AdminListIntegration.
2. **Order statuses:** `pending`, `confirmed`, `preparing`, `ready`, `out_for_delivery`, `completed`, `cancelled`. عرض عربي مركزي، والقيم غير المعروفة لا تُحوّل إلى حالة مخترعة.
3. **Terminal statuses:** `completed`, `cancelled`؛ يتوقف polling تفاصيل الطلب والتتبع عندهما.
4. **Transition rules:** pending → confirmed/cancelled؛ confirmed → preparing/cancelled؛ preparing → ready/cancelled؛ ready pickup → completed/cancelled؛ ready delivery → out_for_delivery/cancelled؛ out_for_delivery delivery → completed فقط. لا انتقال من الحالات النهائية. هذه القواعد موثقة هنا؛ لا توجد نسخة State Machine في Vue.
5. **Transition endpoints:** `POST /api/v1/admin/orders/{uuid}/confirm`, `/start-preparing`, `/mark-ready`, `/dispatch`, `/complete`, `/cancel`. ملاحظة اختيارية `note` حتى 1000 حرف؛ الإلغاء يستخدم `reason`.
6. **available_actions:** مصفوفة نصوص داخل AdminOrderResource؛ `confirm`, `start_preparing`, `mark_ready`, `dispatch`, `complete`, `cancel`، وقد يوجد `collect_cash` في حالات الدفع. الأزرار التشغيلية هي تقاطع هذه المصفوفة مع الصلاحية الفعلية وعمليات هذه المرحلة. لا توليد أزرار بتخمين الانتقال التالي.
7. **Order permissions:** `orders.view`, `orders.confirm`, `orders.start_preparing`, `orders.mark_ready`, `orders.dispatch`, `orders.complete`, `orders.cancel`. `orders.update_status` القديمة لا تكفي لتنفيذ كل العمليات. `/admin/me` هو مرجع الصلاحيات؛ صلاحيات مخصصة تحترم كما يعيدها Backend. الكاشير لا يكمل delivery دون دور إداري يسمح بذلك.
8. **Admin Orders endpoints:** `GET /api/v1/admin/orders` و`GET /api/v1/admin/orders/counts`؛ الأعداد من الخادم ومقيدة بالفلاتر نفسها.
9. **Admin Order Details:** `GET /api/v1/admin/orders/{uuid}`؛ إعادة القراءة بعد العمليات لأن استجابة transition لا تحمل دائمًا كامل العلاقات والإجراءات.
10. **Filters/query parameters:** UI: `search` (رقم/اسم/هاتف)، `status`, `branch`, `type`, `payment_status`, `payment_method`, `order_number`, `date_from`, `date_to`, `sort`, `direction`, `page`, `per_page`. Pagination وفلاتر الخادم فقط. Backend يدعم كذلك `branch_uuid` alias و`customer_uuid` للمطابقة التقنية وsort `created_at`؛ لم تعرض حقول إدخال UUID تقنية للموظف، والبحث يغطي البحث العملي عن العميل.
11. **Status History:** `GET /api/v1/admin/orders/{uuid}/history` مع page/per_page. الحقول: `id`, `from_status`, `to_status`, `source`, `note`, `created_at`, `changed_by:{id,name}`. عرض زمني للقراءة فقط مع صفحات؛ لا تعديل أو حذف أو إنشاء سجل يدوي. metadata الداخلية مخفية.
12. **Cancellation rules:** الموظف بحسب available_actions والصلاحية؛ pending/confirmed/preparing/ready قد تُلغى، ولا إلغاء delivery بعد dispatch أو حالات نهائية. الخادم يتحقق تحت transaction/lock. Vue لا يعيد احتساب المخزون أو الدفع أو refund.
13. **Cancellation endpoints:** admin `/admin/orders/{uuid}/cancel`؛ العميل `POST /orders/{uuid}/cancel`؛ الضيف `POST /orders/{uuid}/guest/cancel` مع `X-Order-Token`.
14. **Cancellation reasons:** نص حر مطلوب 3–500 حرف، ولا قائمة أسباب/endpoint للأسباب. textarea والتحقق الأصلي وعرض Laravel 422، مع تأكيد حساس.
15. **Customer cancellation:** مالك الطلب أو ضيف ذو رمز صالح، pending فقط. Backend يرفض تغير الحالة أثناء الإلغاء؛ الواجهة تعرض الخطأ وتقرأ الحالة الفعلية. لا تغيير حالة قبل نجاح HTTP.
16. **Kitchen endpoints:** `GET /api/v1/admin/kitchen/orders`؛ alias موجود `GET /api/v1/kitchen/orders`. التفاصيل/الإجراءات عبر Admin Order endpoints مع موارد مصفاة للمطبخ.
17. **Kitchen Queue:** الافتراضي confirmed + preparing، ترتيب oldest placed_at ثم id من الخادم. فلاتر branch/status/type وpagination (20 عنصرًا بطلب Vue). يمكن اختيار ready. الفلاتر والصفحة في route query وتبقى بعد refresh. مدة الانتظار مشتقة من confirmed_at أو placed_at الفعلي، ولا تؤثر في الأهلية.
18. **Kitchen actions:** تحميل تفاصيل الطلب للحصول على available_actions ثم بدء التحضير/جاهز أو أي عملية تشغيلية أخرى أعادها الخادم ومنح المستخدم صلاحيتها. لا استنتاج من ترتيب البطاقات أو الحالة وحدها.
19. **Kitchen permissions:** `orders.view` للقائمة والتفاصيل؛ `orders.start_preparing`, `orders.mark_ready` للأفعال المعتادة. لا kitchen permission مخترعة. pure kitchen لا تُعرض له أسعار/اتصال/عنوان/دفع/History المحجوب من AdminOrderResource.
20. **Existing realtime mechanism:** event `OrderStatusChanged` بعد commit وlistener لإشعارات داخلية. لم أجد قناة WebSocket/SSE/Broadcast فعالة لهذا المسار. لم أضف notifications providers.
21. **Chosen mechanism:** polling لواجهات التشغيل والتتبع؛ إلغاء الطلبات القديمة عبر AbortController وحماية revision، وطلب واحد جارٍ لكل قارئ. يتوقف عند إخفاء الصفحة والخروج منها، ويستأنف عند الظهور. يوقف التحديث التلقائي عند 401/403/404/422، ويعرض إعادة المحاولة. بيانات حساسة تُمسح عند رفض الوصول.
22. **Intervals:** Admin list/details/Kitchen كل 15 ثانية؛ Tracking كل 20 ثانية. Pause عند حوار العملية. Backoff للأخطاء الشبكية حتى 120 ثانية؛ 429 ينتظر Retry-After أو 60 ثانية على الأقل، ويمنع refresh اليدوي السريع. وقت المزامنة المحلي مسمى بوضوح ولا يُعرض كوقت انتقال من Backend.
23. **Guest Tracking:** `POST /api/v1/orders/track` بالجسم `{order_number,token}`؛ لا رقم هاتف، ولا token في query أو URL. عند فتح رابط UUID محفوظ، `GET /orders/{uuid}/guest` مع Header يجلب رقم الطلب ثم POST track.
24. **Guest security:** رمز opaque من 64 حرفًا، hashed في الخادم، expiry افتراضية 30 يومًا قابلة للضبط. رمز خاطئ/طلب غير موجود/انتهاء الصلاحية يرجع generic 404. Private/no-store في Backend. حفظ الرمز فقط في sessionStorage مع fallback ذاكرة وتحذير عند عدم الحفظ، دون تخزين OrderResource/PII أو طباعة أسرار. نموذج البحث password field وينظف الرمز عند المغادرة.
25. **Authenticated Tracking:** `GET /api/v1/orders/{uuid}/tracking` مع Bearer مركزي؛ مالك الطلب فقط، وليس صلاحية admin عامة لتجاوز الملكية. لا تتبع خاص باستخدام UUID وحده.
26. **Pickup tracking:** pending → confirmed → preparing → ready → completed. جاهز للاستلام ونهاية الطلب يعرضان من status/timeline في TrackingResource.
27. **Delivery tracking:** pending → confirmed → preparing → ready → out_for_delivery → completed. وجهة snapshot ونافذة delivery ETA من المورد؛ لا سائق/خريطة/إحداثيات حركة مختلقة.
28. **ETA:** ready_at وdelivery_from/to من تقديرات الخادم المحفوظة وقت الطلب، وتستند لوقت التحضير/المنطقة/الموعد المجدول. تظهر فقط عند وجود قيمة، وبصيغة تقديرية؛ لا حساب ETA في Vue أو عداد تسليم يوهم بدقة غير موجودة.
29. **OrderTimeline:** مكوّن موحد يعرض timeline المرسل من TrackingResource بحالات completed/current/upcoming/cancelled، أو History الإداري الفعلي. لا توليد أوقات لمراحل مستقبلية؛ null يظهر غير متاح/لم تبدأ بعد. actor/source/note للسجل الإداري فقط.
30. **New Vue files:** `components/orders/{AdminOrdersBoard,AdminOrderOperations,CustomerOrderCancel,KitchenSnapshot,OrderStatusBadge,OrderTrackingPanel}.vue`؛ `views/admin/{OrderDetailsView,KitchenView}.vue`؛ `composables/{useOrderPolling,useOrderBranches}.js`؛ `constants/orderOperations.js`؛ اختبار `tests/lifecycle.browser.mjs` وهذا التقرير.
31. **Modified files:** `api/orders.api.js`, `constants/orderStatus.js`, `components/orders/OrderTimeline.vue`, `components/site/OrderSnapshot.vue`, `views/admin/{OrdersView,DashboardView}.vue`, `views/site/{TrackOrderView,CustomerOrderView,CustomerOrdersView,OrderSuccessView}.vue`, `layouts/{AdminLayout,SiteLayout}.vue`, `router/index.js`, `config/adminNavigation.js`, `data/adminDashboard.js`, `.gitignore`.
32. **New routes:** `/admin/orders/:orderUuid`, `/admin/kitchen`, `/admin/kitchen/:orderUuid`, `/track-order/:orderUuid`. مسار track-order الحالي أصبح نموذج تتبع فعليًا؛ العمليات الخاصة تطلب تحقق الخادم.
33. **Sidebar:** إضافة المطبخ بصلاحية orders.view، والطلبات الحالية ترتبط بالصفحات الحقيقية؛ لا أقسام دفع جديدة.
34. **Missing Backend UI discovered:** كانت Admin Orders/Dashboard orders demo والتتبع placeholder. غابت تفاصيل فعلية وسجل وإجراءات وإلغاء ومطبخ وتقديرات وتحديث دوري وربط التتبع بالنجاح والعميل.
35. **Implemented missing UI:** قائمة وفلاتر وأعداد وصفحات server؛ تفاصيل snapshots؛ سجل read-only؛ إجراءات مؤكدة؛ سبب إلغاء وأخطاء الحقول؛ queue/details kitchen؛ guest/customer tracking وETA؛ polling؛ status badge مشترك؛ الروابط من نجاح الطلب وصفحات العميل.
36. **Backend-only not exposed:** locks/transactions/events/jobs/stock reservation-consumption-release/token hashes/internal metadata لا تحتاج أدوات تحرير UI. `collect_cash`, payment transactions/webhooks وغرض تسويات الدفع للمرحلة التالية التي استُبعدت صراحة. لا statuses/timestamps/actor editable.
37. **Removed demo:** orders في adminDashboard أصبحت [] ولا تُحمّل سجلات الطلبات التجريبية القديمة؛ استبدال Dashboard orders بالقائمة الحقيقية؛ حذف `useAdminOrdersPreview`, `useAdminOrderDialog`, `adminOrderActions`، ومكونات admin/orders القديمة `OrderActions`, `OrderDetailsModal`, `OrderProgress`. إبقاء بيانات الوحدات الأخرى خارج النطاق؛ helper قديم مستخدم بصفحة Customers خارج النطاق محفوظ للتوافق دون إعادة Orders demo.
38. **End-to-end lifecycle verification:** اختبارات Laravel الحالية المعزولة، ومتصفح Chrome الحقيقي مع Vue/Pinia/Axios واستجابات HTTP fixtures: pickup بأربع عمليات وتسجيل التاريخ، delivery dispatch/complete، وعدم تحديث الحالة تفاؤليًا ومنع إرسال مزدوج. هذا اختبار تكامل واجهة عبر fixture وليس طلبًا ناجحًا على قاعدة live؛ لم تُنشأ دورة staff live دون حساب اختبار معتمد.
39. **Kitchen tests:** Laravel KitchenOrdersTest وLifecycle ضمن التشغيل الناجح؛ Chrome queue/actions/details redaction، عدم استدعاء history المحجوب، بقاء query filters بعد refresh، وعروض 390/768/1440.
40. **Permission tests:** Laravel يغطي roles/custom permissions والرفض؛ Chrome تقاطع available_actions/permissions ومستخدم kitchen محدود وعميل ليس admin. live GET admin/orders وadmin/kitchen/orders بلا اعتماد يرجعان 401. صلاحيات cashier/custom roles التفصيلية تحققت في Laravel لا بادعاء جلسة live.
41. **Invalid transition:** Laravel يغطي 422 وduplicate/conflict؛ Chrome 422 INVALID_ORDER_TRANSITION ظاهر مع refetch وعدم صناعة نجاح. available_actions=[] يحجب التأكيد حتى لو permissions تسمح.
42. **Concurrent update:** Laravel transaction/lifecycle tests؛ Chrome POST 409 مع انتقال قام به مستخدم آخر في fixture، ويعاد تحميل الحالة الحقيقية. إعادة GET قبل POST لا تُغني عن lock/conflict في Backend؛ Vue يحترم الرفض.
43. **Cancellation:** Laravel staff/customer/guest/unauthorized/status/reason ضمن الاختبارات؛ Chrome reason 422 ثم نجاح cancellation وتحديث TrackingResource إلى cancelled. منع إرسال متكرر والتحقق 3–500 موجودان في حوار السبب.
44. **Guest Tracking security:** Laravel security/performance tests؛ Chrome secret في body أو X-Order-Token فقط، session-only، refresh، generic denial ومسح البيانات، 429 cooldown. live POST برقم غير موجود ورمز وهمي صالح البنية يرجع 404. لم تُطبع الرموز أو أرقام اتصال المستخدمين في تقرير الاختبار.
45. **Authenticated Tracking:** Laravel ownership و403/404/session cases؛ Chrome GET tracking بصلاحية العميل، cancellation، والرمز المركزي. live GET دون اعتماد 401؛ لا claim باختبار مالك live معتمد.
46. **Pickup tracking:** بيانات الخادم الحالية والملخص والخيارات وready ETA؛ اختبارات timeline/estimates Laravel والمتصفح؛ null/future times تُعرض بحسب المورد دون اختلاق.
47. **Delivery tracking:** Chrome out_for_delivery ونافذة ETA والوجهة المحفوظة ثم completed؛ Laravel delivery timeline/estimate snapshots. التحديث النهائي يوقف polling؛ اختُبر الانتظار 21 ثانية دون طلب جديد.
48. **Backend issue discovered:** AdminOrderResource يحجب History عن pure kitchen، لكن `/admin/orders/{uuid}/history` محمي فقط بـorders.view ويعيد changed_by/name/note دون redaction مماثلة. الواجهة لا تستدعيه للمورد المحجوب أو لمسار المطبخ؛ المنع على مستوى UI لا يسد الوصول المباشر، ويلزم قرار/تعديل Backend في مهمة مصرح بها. ملاحظة سابقة: guest checkout replay بعد ضياع الاستجابة لا يستطيع إعادة إصدار الرمز الخام ذي الإصدار الواحد؛ UUID وحده لا يفتح التتبع.
49. **Build:** `npm run build` ناجح، 211 modules. تحذير Rollup سابق عن apiError static/dynamic import؛ لا build error ولا dependency جديدة.
50. **Console:** Chrome test diagnostics وassetFailures خاليتان؛ أخطاء HTTP المتوقعة 409/422/429/404 اختُبرت كحالات أعمال، دون uncaught JavaScript exceptions أو بيانات اعتماد في logs.
51. **Remaining limits:** النجاح التشغيلي live بحسابات manager/cashier/kitchen وطلب فعلي لم يُختبر، لأن حسابات اختبار staff المعتمدة لم تتوفر لهذه المهمة؛ التشغيل الناجح مثبت منفصلًا باختبارات Laravel ومع fixture browser. إعدادات الفرع المغلق/عدم السماح بجدولة الطلبات التي رُصدت في المرحلة السابقة لم تُغيّر لفرض النجاح. خلل history المذكور وقيود رمز replay يتطلبان Backend scope. لا WebSocket أو تتبع سائق Backend لاستعماله. Payments لم تُبدأ.

## BACKEND → FRONTEND COVERAGE

| Backend feature | Existing UI | Missing UI implemented | Permission / endpoint |
|---|---|---|---|
| قائمة الطلبات والفلاتر/الأعداد | Demo board | Server board + search/filter/counts/pagination، Dashboard compact | orders.view; admin/orders + counts |
| تفاصيل snapshots | Customer OrderSnapshot فقط | Admin details + Kitchen redacted details | orders.view; admin/orders/{uuid} |
| allowed transitions | Demo actions | available_actions ∩ permissions، confirmation، notes، refetch/errors | specific operations POST |
| immutable history | لا يوجد | Read-only Timeline + pagination/source/actor/note | orders.view; /history، بشرط عدم redaction |
| cancellation | لا يوجد | Admin + customer/guest dialogs + reason 422 | orders.cancel أو owner/guest contract |
| Kitchen queue | لا يوجد | Operational cards, oldest-first API، filters/page، elapsed، actions | orders.view + action permissions |
| Customer tracking | تفاصيل ثابتة | Owner GET tracking، status/timeline/ETA/polling، cancellation | Bearer owner |
| Guest tracking | placeholder | POST token-body form، private session token، refresh via Header | guest credential |
| pickup/delivery ETA/timeline | لا يوجد | عرض المورد فقط، جاهز/خرج للتوصيل/terminal | TrackingResource |
| status presentation | تكرار وديمو | Shared order status badge وcentral actual labels؛ payment منفصل | resource fields |
| order-success/customer links | snapshots | روابط التتبع الفعلي وتحديث الحالة في customer snapshot | UUID + access verification |
| events/locks/stock/internal hashes | Backend-only | تبقى داخل Backend؛ لا أدوات تعديل | غير مطلوب UI |
| collect_cash/payment/webhooks | مرحلة أخرى | مؤجل صراحة | Payments next module |

## NEXT MODULE READINESS

أساس الواجهة جاهز لبدء **تدقيق** Payments + Payment Transactions + Cash Collection + Online Payment Preparation + Payment Webhooks visibility في المهمة التالية: Axios/auth/error foundation، صلاحيات حقيقية، تفاصيل طلب فعلية، فصل order status عن payment status، refetch بعد العمليات وavailable_actions موجودة.

هذا لا يعني اكتمال Payments أو جاهزية gateway للإنتاج. يلزم في المرحلة التالية فحص العقود والاختبارات الحالية للدفع والتحصيل والـwebhooks، وتمثيلها دون اختراع provider أو callback أو صلاحيات، والتحقق بحسابات اختبار وبيئة مناسبة. لم أضف شاشة دفع أو زر collect_cash أو provider/webhook integration في هذه المهمة.

## Verification evidence

- Laravel: `php artisan test --compact --filter='OrderLifecycle|OrderCancellationTest|KitchenOrdersTest|OrderTracking|OrderTransactionTest|AdminOperationsTest|AdminListIntegrationTest'` — **204 tests / 1263 assertions PASS**، قاعدة اختبارات معزولة.
- Chrome: `node --experimental-websocket tests/lifecycle.browser.mjs`، تفاصيل في `.lifecycle-check/report.json` (ملف محلي مستبعد من Git؛ fixtures محصورة في tests).
- Browser responsiveness: خمس واجهات × ثلاثة عروض؛ لا overflow أفقي. polling يستخدم مدد الإنتاج، وليس timer acceleration.
- Live Laravel: three unauthorized GETs →401؛ invalid guest tracking POST →404. لا positive staff live claim.
- Build: `npm run build`.
