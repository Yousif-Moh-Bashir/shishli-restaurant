# Notifications — Real API Integration

تم تنفيذ الواجهة فوق عقد Laravel الموجود، دون تعديل Backend أو Old HTML. لا توجد استدعاءات لمزودي الرسائل من Vue أو بيانات إرسال تجريبية داخل التطبيق. اختبار المتصفح يستخدم استجابات HTTP معزولة في ملف الاختبار فقط؛ اختبارات Laravel تستخدم المزودات الآمنة الموجودة أصلًا وSQLite في الذاكرة. لم تُرسل رسائل SMS/WhatsApp/Email حقيقية.

## التقرير المطلوب — 50 بندًا

1. **ملفات Backend المفحوصة:** `routes/api.php`؛ `Http/Controllers/Api/V1/Admin/NotificationController.php`؛ `Http/Requests/Api/V1/Admin/ListNotificationsRequest.php`؛ Resources: `OutboundNotificationResource.php`, `NotificationAttemptResource.php`؛ Models: `OutboundNotification.php`, `NotificationAttempt.php`؛ `Actions/Notifications/{SendNotificationAction,RetryNotificationAction}.php`؛ جميع `Contracts/Notifications/*.php` وجميع `Services/Notifications/*.php`؛ Enums: `NotificationStatus`, `NotificationChannel`, `NotificationType`, `NotificationErrorCode`؛ Events: `OrderPlaced`, `OrderStatusChanged`, `PaymentSucceeded`, `PaymentFailed`؛ Listeners الأربعة المبينة أدناه؛ `Jobs/SendNotificationJob.php`؛ `config/outbound_notifications.php` وعقد mail/queue؛ `database/seeders/RolePermissionSeeder.php`؛ migration `2026_10_01_232406_create_outbound_notification_tables.php`؛ اختبارات `NotificationsTest`, `NotificationEventsTest`, `NotificationIsolationTest`, `NotificationTemplatesTest` ومزوداتها في `tests/Support`. ملفات `app/` المشار إليها نسبية إلى `back-end/app/`. تمت مراجعة ربط إنشاء الطلب وانتقال حالته ونجاح/فشل الدفع عبر اختبارات العزل والأحداث، وليس تخمين أسماء الأحداث.

2. **Endpoints الفعلية:** جميع المسارات التالية تبدأ بـ `/api/v1` وتحتاج مصادقة وحساب إدارة نشط:

   | Method | Endpoint | Permission | الاستجابة |
   |---|---|---|---|
   | GET | `/admin/notifications` | `notifications.view` | قائمة paginated، مستلم masked |
   | GET | `/admin/notifications/{uuid}` | `notifications.view` | التفاصيل والمحتوى الكامل المسموح |
   | GET | `/admin/notifications/{uuid}/attempts` | `notifications.view` | سجل محاولات paginated، 20 في الصفحة |
   | POST | `/admin/notifications/{uuid}/retry` | `notifications.retry` | جسم `{}`، HTTP 202، snapshot جديد من الخادم |

3. **Statuses:** `pending`, `processing`, `sent`, `failed`, `cancelled`. لا توجد حالة `queued` أو `delivered` أو `read` في العقد. Utility العرض لا ينفذ state machine.

4. **Channels:** `sms`, `whatsapp`, `email`. وجود القناة في Enum لا يعني وجود مزود فعال لها أو تفعيلها لكل نوع.

5. **Provider interfaces:** `SmsProviderInterface`, `WhatsAppProviderInterface`, `EmailProviderInterface`؛ كل منها يستقبل DTO للقناة ويعيد `NotificationProviderResult`. DTOs تشمل مفتاح idempotency ثابت للإشعار. Vue لا تستخدم هذه العقود الداخلية مباشرة.

6. **Implementations:** يوجد `LaravelEmailProvider` مسجل باسم `laravel`. لا يوجد implementation إنتاجي مسجل لـ SMS أو WhatsApp. ملفات `FakeSmsProvider`, `FakeWhatsAppProvider`, `FakeEmailProvider`, `FakeNotificationProvider` للاختبارات فقط؛ `NotificationProviderManager` يمنع مزودًا من namespace `Tests` خارج بيئة testing.

7. **OutboundNotification:** UUID، النوع، القناة، الحالة، المستلم واسمه، subject/content snapshots، المزود ومعرف الرسالة، reference type/UUID، deduplication key، attempts/max_attempts، آخر خطأ، sent_at/failed_at، metadata، processing token/started_at، وتواريخ Model. Content/recipient/reference/type/channel/template snapshot غير قابلة للتعديل. Resource لا تعرض metadata أو processing token أو deduplication key أو template name/parameters. لا يوجد `scheduled_at`, `can_retry` أو `available_actions` في Resource.

8. **NotificationAttempt:** UUID، notification_id داخلي، attempt_number، status، provider/provider_message_id، error_code/error_message، started_at/finished_at، metadata. Resource تعرض فقط UUID والحقول الآمنة و`manual` المشتقة من metadata. المحاولة المكتملة immutable والحذف ممنوع.

9. **العلاقة:** الإشعار عملية منطقية واحدة؛ `attemptLogs()` علاقة hasMany مرتبة برقم المحاولة؛ Attempt belongsTo Notification. UUID الإشعار ثابت عبر retries، وتُضاف محاولة عند بدء worker للإرسال، وليس بمجرد قبول POST. UI تعرض السجل مستقلًا عن حالة الإشعار وعن Order Status Timeline.

10. **الأحداث ومصفوفة الإرسال:** القناة تحددها إعدادات النوع مع تفعيل القناة العام، والمستلم من Order snapshot، والنص من renderer، والإرسال عبر `SendNotificationJob`:

    | Event الفعلي | Notification Type | الشرط/المرجع |
    |---|---|---|
    | `OrderPlaced` | `order_placed` | Order UUID |
    | `OrderStatusChanged` → confirmed | `order_confirmed` | Order UUID |
    | نفسه → preparing | `order_preparing` | Order UUID |
    | نفسه → ready | `order_ready` | نص الاستلام يختلف عن التوصيل |
    | نفسه → out_for_delivery | `order_out_for_delivery` | Delivery فقط؛ Pickup لا يولد هذا النوع |
    | نفسه → completed | `order_completed` | Order UUID |
    | نفسه → cancelled | `order_cancelled` | لا يوجد حدث منفصل مطلوب باسم OrderCancelled |
    | `PaymentSucceeded` | `payment_succeeded` | Payment UUID، محتوى Order snapshot |
    | `PaymentFailed` | `payment_failed` | Payment UUID، محتوى Order snapshot |

    الانتقال إلى pending لا يطلق نوعًا. المصفوفة توثيق وليست إعدادات قابلة للتحرير في Vue.

11. **Listeners:** `QueueOrderPlacedNotifications`, `QueueOrderStatusChangedNotifications`, `QueuePaymentSucceededNotifications`, `QueuePaymentFailedNotifications`. إنشاء snapshot/جدولة Job يحدث في Laravel بعد commit؛ listener لا يستدعي مزودًا من Vue ولا ينتظر تسليم الرسالة.

12. **Job:** `SendNotificationJob` implements `ShouldQueue` و`ShouldBeUniqueUntilProcessing`، يحمل UUID فقط، `uniqueFor=120`، `afterCommit`، queue `notifications`، timeout 30 ثانية و`failOnTimeout=true` افتراضيًا.

13. **Queue behavior:** الاتصال الافتراضي `database`، والمسموح durable drivers: database/redis/sqs/beanstalkd. Dispatcher يرفض sync، ويحتوي فشل dispatch؛ يسجل `QUEUE_DISPATCH_FAILED` ولا يتراجع عن عملية الطلب/الدفع الملتزمة. لا توجد API إدارية لعرض jobs/payloads/queue IDs؛ الواجهة تعرض حالة الإشعار ومحاولاته فقط. الإرسال إلى المزود خارج transaction. Lease يمنع المحاولات المتداخلة؛ انتهاء lease غير محسوم ينتج `WORKER_INTERRUPTED` ويتطلب مراجعة قبل manual retry.

14. **Automatic retry:** Laravel تصنف الأخطاء المؤقتة (timeout، connection failure، provider rate limit/unavailable)؛ يسجل failed attempt ثم يعيد الإشعار إلى pending إذا بقيت ميزانية. الأخطاء الدائمة أو استنفاد الميزانية تتوقف في failed. Vue لا تعيد POST تلقائيًا ولا تستنتج retryability من نص الخطأ؛ الـ Resource لا توفر هذه الخاصية. تُظهر آخر خطأ آمن وحالة فعلية ووسم المحاولة التلقائية/بطلب موظف.

15. **Limits/backoff:** max_attempts وJob tries افتراضيًا 3؛ backoff config `[30,120,600]`، وبالحد الافتراضي تستعمل retries تأخير 30 ثم120 ثانية قبل المحاولة الثالثة. Processing overlap يعاد تحرير Job بعد lease الافتراضي60 ثانية. UI تعرض counts/limit القادمة من Resource دون محاكاة ميزانية worker أو عد تنازلي وهمي للمحاولات التلقائية.

16. **Manual retry:** POST endpoint أعلاه؛ `RetryNotificationAction` تقبل failed فقط تحت row lock، تعيد نفس الإشعار إلى pending، تمسح failed_at، تحدّث provider المسجل، تجعل max_attempts=`attempts+1` وتعلّم المحاولة التالية manual، ثم dispatch بعد commit. لا حقول recipient/channel/reason/attempts في الجسم. طلب على حالة أخرى يرجع422 في `notification`. UI تحتاج confirmation وصلاحية retry، وتقرأ GET حديثًا قبل POST وتعيد GET التفاصيل/المحاولات بعده. استجابة POST لا تحتوي content الكامل؛ لذلك لا تستبدل تفاصيل GET بها. 202 لا يعني sent؛ تُعرض الحالة التي سجلها الخادم.

17. **Idempotency/concurrency:** unique deduplication key=`referenceType:referenceUuid:type:channel` يمنع تكرار الإشعار عند تكرار Event؛ Job uniqueness/row lock/processing token تمنع إرسالًا متداخلًا وتسوية نتيجة worker قديم. المفتاح للمزود ثابت UUID. لا يوجد Idempotency-Key/operation ID خاص بطلب manual retry؛ UI تمنع double submit وتقرأ حالة حديثة. إذا انقطعت الشبكة ثم بقي failed، أول confirmation لاحقة للتحقق فقط، ثم يلزم confirmation أخرى قبل POST؛ يستمر ذلك عند إغلاق/فتح الحوار. لا تدّعي exactly-once delivery، خصوصًا مع SMTP الذي لا يضمن deduplication من header المرجع.

18. **Recipient resolution:** Snapshot هاتف/بريد/اسم العميل أو الضيف المخزن وقت الطلب، وليس profile قابلًا للتغير. Email يُفحص بـ FILTER_VALIDATE_EMAIL وحد254. الهاتف يقبل E.164؛ `00` تتحول إلى `+`، ولا يفترض بلدًا لرقم محلي؛ تحويل SA المحلي فقط إذا ضبط backend البلد صراحةً. المستلم المفقود يتخطى الإشعار؛ المستلم الموجود غير الصالح يحتفظ بسجل failed و`INVALID_RECIPIENT` دون job أو محاولة إرسال. Vue لا ترسل/تعدل المستلم.

19. **SMS:** Interface وDTO وقواعد recipient والمحاولات موجودة، لكن providers.sms فارغة والقناة معطلة في config الافتراضية. UI تعرض سجل SMS الموجود إن أعاده Laravel؛ لا زر إرسال تجريبي أو ادعاء تشغيل مزود حقيقي.

20. **WhatsApp:** Interface/DTO/templates snapshot موجودة، providers.whatsapp فارغة وwhatsapp_templates فارغة، والقناة معطلة. يجب ضبط مزود إنتاجي وأسماء قوالب معتمدة في Backend قبل إرسال فعلي. الإعداد الافتراضي للأنواع يسمح WhatsApp لـ placed/confirmed/ready/out_for_delivery/cancelled فقط عند التفعيل العام أيضًا. لا تعديل قنوات أو قوالب WhatsApp من Vue.

21. **Email:** Implementation فعلي يستخدم Laravel Mail `raw` للنص، subject خاص بالبريد، وheader `X-Notification-Reference`. يسمح transports smtp/ses/mailgun/postmark/sendmail ويرفض log/array كنجاح إرسال. قبول النقل يسجل sent ومعرف الرسالة؛ لا يثبت وصولها/قراءتها. Email معطل افتراضيًا ولا شيء في Vue يفعّل SMTP أو يقرأ credentials.

22. **Template architecture:** `NotificationTemplateRenderer` داخل الكود، nine types برسائل عربية، وOrderReady بحسب pickup/delivery. Snapshot يحتفظ بالنص/subject والمستلم وبـ WhatsApp template metadata. UI تعرض النص المحفوظ من GET details كما هو عبر Vue interpolation؛ لا HTML editor أو v-html أو إعادة توليد من بيانات منتج/عميل حديثة.

23. **Template CRUD:** لا table/template CRUD/preview/activate/deactivate API. لذلك لا fake editor، ولا fake preview، ولا endpoints افتراضية.

24. **Variables:** Renderer يستخدم orderNumber/orderType/customerName/status label في الكود، وWhatsApp parameters المرتبة `[customerName,orderNumber,label]` مع language الافتراضيar وname المعتمد من config. لا placeholders يختارها Admin ولا قائمة allowed variables API، ولا تعرض Resource هذه metadata.

25. **Delivery webhooks:** لا notification delivery/read/provider-webhook endpoints في routes المفحوصة. UI لا تصنع delivered/read أو تستدعي webhooks. `sent` تعني نتيجة إرسال مسجلة وليست read receipt.

26. **Admin coverage:** القائمة، التفاصيل، المحاولات، وإعادة المحاولة مرتبطة بكل APIs الأربع؛ links إلى الطلب/الدفع بحسب reference.type والصلاحيات. لا manual send/bulk send/cancel/update-recipient API.

27. **Filters:** Laravel يدعم status/channel/type/provider/reference_uuid، recipient أوsearch (LIKE آمن على المستلم فقط، recipient يأخذ الأولوية)، date_from/date_to، sort(created_at/status/channel/type)، direction(asc/desc)، page/per_page(1–100). الواجهة تعرض search وحد150 الفعلي، القناة/النوع/الحالة/provider، التاريخين والترتيب؛ reference_uuid يأتي من links الطلب/الدفع دون محرر UUID. حجم القائمة20 والمرتبطة5، والمحاولات20 من Backend؛ جميع النتائج والترقيم server-side. لا بحث مزعوم برقم الطلب أو filter على الصفحة الحالية. تواريخ القائمة يفصلها Backend وفق timezone لديه.

28. **Permissions:** `notifications.view` للقراءة والصفحات وSidebar، `notifications.retry` للعملية. Manager/superadmin لهم الصلاحيات الافتراضية، cashier/kitchen/customer لا؛ UI تستخدم auth permissions الفعلية وليس أسماء الأدوار. الصلاحيتان مستقلتان في Backend: retry-only يمكنها POST لكن لاGET؛ UI التفاصيل تتطلبview ولا تنشئ محرر UUID لتجاوز ذلك.401 تنظف الجلسة والبيانات؛403 تعرض الخطأ دون logout؛404 تخفي التفاصيل؛422 تعرض أخطاء Laravel؛429 توقف إعادة محاولة الإرسال مؤقتًا وفق Retry-After مع حد60 ثانية. Polling لديه backoff/cooldown واستجابات stale محمية.

29. **Order visibility:** أضيفت `RelatedNotifications` إلى Admin Order Details بإذنview وخارج Kitchen. الطلب يستعمل reference_uuid=Order UUID؛ قراءة واحدة للقائمة paginated، لا attempts request لكل سطر. ملخص نوع/قناة/حالة ووقت إرسال وخطأ آمن وروابط التفاصيل والسجل المقيد. Payment Details تملك القسم نفسه بمرجع Payment UUID لأن إشعارات الدفع ليست Order reference. Order Timeline لم تُخلط بالمحاولات؛ فشل الرسالة لا يعدل حالة الطلب أو الدفع.

30. **Customer notification API:** لا توجد endpoint مخصصة للعميل/الحساب للقراءة أو التاريخ أو الاشتراك. لم تُعرض admin logs أو recipient/provider internals في موقع العميل، ولم يضف customer notifications route.

31. **ملفات Vue الجديدة:** `src/api/notifications.api.js`؛ `src/utils/notificationStatus.js`؛ `src/components/notifications/{NotificationBadge,NotificationRetry,RelatedNotifications}.vue`؛ `src/views/admin/{NotificationsView,NotificationDetailsView}.vue`. وللتحقق فقط `tests/notifications.browser.mjs`؛ وهذا التقرير.

32. **الملفات المعدلة:** `src/router/index.js`، `src/constants/adminNavigation.js`، `src/views/admin/OrderDetailsView.vue`، `src/views/admin/PaymentDetailsView.vue`، `.gitignore` لإخفاء ملف profile/report الخاص بالاختبار. استُخدمت Axios/auth/normalizeApiError/useApiState/useOrderPolling/ApiPagination الموجودة، دون تغييرها لهذه المهمة. لم تُعدل ملفات Laravel أو Old HTML أو Customers.

33. **Routes:** `/admin/notifications` باسم `admin.notifications` و`/admin/notifications/:notificationUuid` باسم `admin.notification`، كلاهما داخل Admin shell وبـ `notifications.view`.

34. **Sidebar:** عنصر «الإشعارات» ضمن navigation الحالية وبنفس الهوية والأيقونات، ظاهر بإذنview فقط.

35. **Missing UI المكتشفة:** لم تكن هناك إدارة notifications مربوطة بالقائمة/details/attempts/retry، ولا أقسام notifications بالطلب أو الدفع. لم توجد fake templates UI تحتاج ربطًا.

36. **Missing UI المنفذة:** كل القراءة والعملية المدعومة، server filters/pagination، تفاصيل plaintext، سجل attempts منفصل، retry confirmation/reconciliation/errors، links للكيان المرتبط، قراءات UUID للطلبات/المدفوعات، وحالات loading/empty/error/auth. التفاصيل تحدث كل20 ثانية فقط للحالتين pending/processing والصفحة ظاهرة، وتتوقف عند terminal وأثناء تأكيد retry؛ القائمة والأقسام المرتبطة manual refresh لمنع polling دائم أو N+1.

37. **Backend-only:** resolution/templates/event mapping/channel activation/providers/dedup/queue leases/backoff/token/internal metadata بقيت Laravel-only. لا APIs تدعم تحريرها أو عرض raw job/provider payloads؛ تعرض الواجهة Resource الآمنة فقط. لا editable timestamps/IDs أو محاولة تاريخية قابلة لتعديل المستخدم.

38. **Demo data:** لم توجد بيانات demo خاصة بإشعارات الإنتاج لإزالتها؛ جميع الصفحات الجديدة تستخدم API ولا fallback. Fixtures داخل `tests/notifications.browser.mjs` فقط، والـ Chrome profile/report داخل `.notifications-check/` المهملة بـGit. بيانات الوحدات غير المشمولة بقيت كما كانت.

39. **Event → Notification:** اختبارات Laravel القائمة نجحت في mapping الأحداث، وبعد commit وعدم الإنشاء عند rollback، ومنع التكرار، وتعطيل القنوات/الأنواع، وpickup/delivery، وتثبيت recipient/content snapshots. `NotificationIsolationTest` ينشئ Order عبر checkout وDB queue ثم يشغل worker `--once` مع المزود الآمن الموجود؛ ويتحقق من فشل notification/attempt مع بقاء الطلب committed. هذا اختبار مسار فعلي مع fake provider داخل testing، وليس رسالة إنتاجية حقيقية.

40. **Attempts:** نجحت اختبارات تسجيل أرقام المحاولات، وثبات التاريخ المكتمل، والحالات والأوقات والمزود والخطأ الآمن وmanual flag ومنع التداخل. اختبار المتصفح يقرأ ترقيم المحاولات من API، ويحترم حجم الصفحة الثابت20 ولا يختلق finished_at أو يغير التاريخ. تفاصيل snapshot تُقرأ مجددًا بعد retry، فلا تختفي بسبب Resource POST الجزئية.

41. **Failures:** نجحت اختبارات Laravel للمستلم والقالب والمزود غير الصالح، والأخطاء الدائمة، واستثناء المزود، وانقطاع worker، وفشل dispatch وإطلاق uniqueness lock مع بقاء الطلب والتحصيل صحيحين. المتصفح يغطي 500/422/403/404/401/429 وانقطاع الشبكة قبل وبعد commit؛ لا نجاح مزيف أو بيانات demo أو حالة sent مبنية على توقع محلي.

42. **Automatic retry tests:** نجحت اختبارات timeout/connection/rate limit/unavailable، وbackoff بقيم30/120، واستنفاد المحاولات والتوقف عند permanent error أو stale lease؛ مفتاح idempotency ثابت. Polling الواجهة قراءة فقط ولا يشغل provider أو manual retry.

43. **Manual retry tests:** اختبارات Laravel تتحقق من failed فقط، ونفس UUID، وميزانية محاولة جديدة واحدة وتاريخ manual، و422 لطلب مزدوج على pending. المتصفح يتحقق من confirmation، وGET حديثة، ومنع double submit، و202 pending، وتغير الحالة إلى sent قبل التأكيد دون POST، وإعادة القراءة بعد409/422، وتهدئة429، وتسوية انقطاع الشبكة عبر تأكيد للتحقق فقط قبل تأكيد إرسال جديد، حتى عند failed سريع بعد commit.

44. **Permission tests:** اختبارات Laravel تتحقق من المصادقة والأدوار واستقلال notifications.view/retry. المتصفح يتحقق من القراءة دون retry لمستخدم view-only، ومنع Kitchen في route وSidebar، وتنظيف الجلسة والبيانات عند401، وعدم logout عند403، وإخفاء snapshot عند404. تفاصيل الكيانات وروابطها مرتبطة بـ orders.view/payments.view.

45. **Provider tests:** العقود وprovider manager، ومنع Tests providers في production، والنجاح والفشل لكل channel عبر safe fakes الموجودة، وLaravelEmailProvider عبر Mail mock ونتيجة SMTP الآمنة، وتصنيف أخطاء النقل، ورفض log/array، وقوالب WhatsApp المعتمدة وتوحيد الهاتف؛ جميعها ضمن **114 اختبارًا ناجحًا و444 assertion**. لم يحدث live send لرقم أو بريد عشوائي أو حقيقي.

46. **هل توجد providers حقيقية؟** Email adapter حقيقي موجود؛ SMS وWhatsApp abstractions وtest fakes فقط، ولا implementation إنتاجي مسجل لهما. القنوات الثلاث معطلة في config الافتراضية. قراءة السجل والمحاولات وretry API مربوطة، لكن إرسالًا إنتاجيًا يحتاج إعداد Backend والمزود والنقل خارج هذه المهمة؛ لم توضع secrets في VITE أو Vue.

47. **Backend limitations الموثقة:** لا can_retry/available_actions/automatic-retryable flag بالـ Resource؛ UI تستعمل failed مع permission بما يوافق Action وتترك القرار للخادم. لا operation ID لتسوية manual retry بعد انقطاع الشبكة؛ لذلك التحقق محافظ ولا ضمان exactly-once. لا provider-capability/status API ولا templates CRUD/customer history/queue jobs/delivery webhook APIs. رد retry يكون masked دون content فتليه GET. هذه حدود العقد، ولم تُحل بإضافة Backend أو endpoints وهمية.

48. **Build:** `npm run build` نجح؛ 235 modules؛ JS451.38kB وCSS59.85kB قبل gzip. التحذير السابق لاستخدام apiError بشكل static/dynamic بقي دون تأثير على النجاح. ظهرت أيضًا ملاحظة توقيت plugin بناء CSS؛ ليست خطأ build أو runtime.

49. **Console/browser verification:** نجح المتصفح في **9 مجموعات سيناريوهات و15 فحص تجاوب** عند390/768/1440 للقائمة والتفاصيل والمحاولات وحوار retry وأقسام الطلب والدفع، دون overflow على مستوى الصفحة. لا Vue warnings أو runtime errors أو assets404. المصدر `tests/notifications.browser.mjs` والمخرجات المحلية `.notifications-check/report.json`. fixtures المتصفح منفصلة عن live API واختبارات Laravel؛ حالات انقطاع الشبكة متعمدة في الاختبار.

50. **المتبقي:** لم تُرسل رسائل خارجية حقيقية أو يُختبر تسليمها، ولا توجد staff session مصرح بها لاختبار retry على مستلم حقيقي. أعادت المسارات الأربعة401 في live checks غير المصادقة دون dispatch. تشغيل SMS/WhatsApp/email في الإنتاج وتفعيل الإعدادات والـ worker وcredentials مهمة Backend تشغيلية منفصلة. لا مانع معروف متبقٍ في ربط UI للـ API المتاحة؛ لا ادعاء تشغيل قناة غير مهيأة.

## BACKEND → FRONTEND COVERAGE

| Backend feature | Existing UI / الفجوة | UI المنفذة | العقد/الحد |
|---|---|---|---|
| Notifications index/history | لا سجل مربوط | القائمة والبحث والفلاتر وserver pagination | notifications.view؛ recipient masked |
| Details snapshot | لا تفاصيل | الحالة/content/subject/recipient/times/error الآمنة | content فقط GET show، plaintext escaped |
| Attempt history | لا سجل | رقم/حالة/provider/error/manual/timestamps/pagination | قراءة فقط؛ لا raw metadata |
| Failed manual retry | لا عملية | confirmation+fresh GET+POST{}+fullrefetch | failed + notifications.retry؛ Laravel row lock |
| Pending/processing status | لا مزامنة | قراءة كل20 ثانية، توقف عند إخفاء الصفحة والنتيجة النهائية، backoff | لا provider أو manual retry تلقائي |
| Order references | لا قسم | RelatedNotifications داخل Admin Order Details | reference_uuid، permission؛ خارج Kitchen |
| Payment references | لا قسم | نفس القسم بـ Payment UUID | مرجع الدفع؛ سجل منفصل عن Order Timeline |
| sms/whatsapp/email records | لا عرض منظم | badge/channel/type mappings من enums الفعلية | لا ادعاء قناة فعالة من Enum |
| Email implementation | Laravel-only | عرض نتيجة Resource ومرجع الرسالة | لا SMTP في Vue؛ sent≠read |
| Templates/recipients/events | داخل الخادم | قراءة snapshot content | لا editing API أو CRUD وهمي |
| Queue/retry/dedup internals | داخل الخادم | counts/status/history فقط | لا queue jobs API أو payloads |
| Customer notifications | API غير موجودة | لم تضف واجهة | لا استخدام admin API للعميل |

## نتائج التحقق

- Laravel: `php artisan test --compact --filter='NotificationsTest|NotificationEventsTest|NotificationIsolationTest|NotificationTemplatesTest'` — **114 passed، 444 assertions**، SQLite `:memory:` وsafe provider fakes الموجودة؛ لا تعديل مصدر Backend.
- Browser: `node --experimental-websocket tests/notifications.browser.mjs` — **9 مجموعات ناجحة، 15 فحص تجاوب**. تأكد تحديث processing إلى sent عند poll بعد20 ثانية وتوقفه خلال21 ثانية تالية؛ بقيت القائمة دون طلب تلقائي خلال21 ثانية إضافية.
- Live API: GET index/show/attempts وPOST retry دون مصادقة — **401 لكل المسارات الأربعة**؛ لا إرسال أو worker على قاعدة التشغيل.
- Production build: **ناجح**، دون أخطاء. Console/asset failures: **صفر**.

## NEXT MODULE READINESS

المشروع جاهز **لبدء تدقيق وربط المرحلة التالية**. لم تُربط Customers UI في هذه المهمة؛ تمت مراجعة routes/controllers/resources اللازمة لتقييم الجاهزية فقط، دون تنفيذ إجراءات تغير عملاء حقيقيين.

| المجال التالي | Backend support موجود | التقييم |
|---|---|---|
| Customers | GET `/admin/customers`, GET `/admin/customers/{uuid}` | جاهز لتدقيق القائمة والتفاصيل بـ customers.view؛ الصفحة الحالية خارج هذه المهمة |
| Customer Profiles | GET/PATCH `/profile` وCustomerProfileResource | جاهز لربط العقد وvalidation؛ لا افتراض CRUD عملاء إداري غير موجود |
| Guest Customer Aggregation | GET `/admin/guest-customers`, GET `/admin/guest-customers/orders` | جاهز لتدقيق aggregation بالهاتف وقواعد الإخفاء والترقيم |
| Order History | GET `/admin/customers/{uuid}/orders` وAPIs طلبات العميل المملوكة الحالية | جاهز؛ تدقيق الملكية والفلاتر وsnapshots أولًا |
| Addresses | GET `/admin/customers/{uuid}/addresses`؛ customer address CRUD مربوط سابقًا | جاهز لإظهار admin read-only وفق API، دون تخمين admin mutation |
| Customer Statistics | statistics في CustomerProfileResource/AdminCustomerResource عبر CustomerStatisticsService | جاهز؛ المجاميع من Backend، دون frontend aggregation أو endpoint افتراضية |
| Guest Order Claiming | POST `/profile/claim-orders`، throttle10/min، نتيجة claimed_orders | API موجودة؛ تتطلب active+verified phone+normalized matching؛ phone/user IDs ممنوعة بالطلب؛ claim يبطل توكن الضيف ويحافظ على المالك الموجود |
| Activation/Deactivation | POST `/admin/customers/{uuid}/activate`, `/deactivate` | جاهز لتدقيق العمليات بـ customers.activate/customers.deactivate وconfirmation/auth |

قيد مهم للمرحلة التالية: ClaimGuestOrdersAction تتطلب هاتفًا verified بالفعل. لم توجد ضمن foundation السابقة واجهة OTP/phone verification مكتملة؛ يجب تدقيق ذلك من جديد قبل الادعاء بأن عميلًا جديدًا يمكنه إكمال claim من البداية للنهاية. اختبارات Customers الموجودة تحتاج قراءتها وتشغيلها في مهمتها؛ لم تُعتبر ناجحة لمجرد وجود الملفات. توقفت المهمة عند Notifications؛ لم تبدأ Customers/Reports/Settings/CMS.
