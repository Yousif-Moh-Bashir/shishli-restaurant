# Checkout + Order Creation Integration

تاريخ التحقق: 2026-10-04. Laravel هو مصدر قواعد العمل والأسعار والملكية والنسخ الثابتة. جميع تعديلات المصدر في `front-end`؛ لم أعدل مصدر Laravel أو التصميم القديم أو التبعيات. لم تبدأ إدارة دورة الطلب أو المطبخ أو بوابات الدفع أو التتبع المتقدم.

## التقرير المطلوب — 48 نقطة

1. **ملفات Backend المفحوصة:** `routes/api.php`؛ Controllers: `CheckoutController`, `OrderController`, `GuestOrderController`, `MenuController`, `PublicConfigurationController`, وDelivery Quote/Addresses من التكامل السابق؛ Requests: `CheckoutRequest`, `ListOrdersRequest`, `StoreCustomerAddressRequest`, `MenuRequest`؛ Resources: `OrderResource`, `OrderItemResource`, `OrderItemOptionResource`, `OrderAddressResource`, `CartResource`, `BranchAvailabilityResource`, `PublicConfigurationResource`؛ Models: `Order`, `OrderItem`, `OrderItemOption`, `OrderAddress`, وCart/CustomerAddress؛ Actions: `PlaceOrderAction`, `RepriceCartAction`, `ReserveOrderStockAction`؛ Services: `CartResolver`, `CartStateService`, `PricingService`, `DeliveryService`, `DeliveryAddressData`, `BranchAvailabilityService`, `OrderTimingService`, `OrderNumberGenerator`, `OrderEstimateService`, `GuestOrderAccess`, `CustomerOrderQuery`, `RestaurantSettingsService`, `RestaurantSettingDefinition`, والترويج الذي تستدعيه عملية Checkout؛ Middleware: `OptionalCartAuthentication`؛ Enums: OrderType/OrderTiming/OrderStatus/PaymentMethod/PaymentStatus/CartStatus؛ Event: `OrderPlaced`؛ `ApiResponse`, `AppServiceProvider`, `config/rate_limiting.php`؛ migrations: إنشاء order/cart tables، lifecycle، customer fields، category snapshots، operational settings/schedules، secure guest tracking. تمت مراجعة اختبارات `CheckoutTest`, `OrderAccessTest`, `OrderTransactionTest`, `OperationalAvailabilityTest`, `InventoryOrderIntegrationTest`, وأجزاء `RestaurantSettingsTest`. لا توجد OrderPolicy مخصصة لمسارات العميل؛ التحقق في Controller/Resolver/GuestOrderAccess.

2. **Checkout endpoint:** `POST /api/v1/checkout`. Optional Sanctum authentication؛ Bearer غير صحيح لا يتحول إلى ضيف. Throttle الحالي 10 محاولات/دقيقة، بحسب المستخدم المسجل أو IP للضيف. 429 يعطل التأكيد وفق Retry-After؛ إذا لم تكشفه CORS للمتصفح ينتظر UI60 ثانية، وهي نافذة throttle الحالية، مع بقاء Backend المرجع. HTTP201 للطلب الجديد وHTTP200 لإعادة السلة المحولة. Wrapper: `success/message/data/errors`. الرد `private, no-store`.

3. **Request الفعلية:** `type` pickup/delivery، `customer:{name,phone,email?}`، `payment_method` cash/card، `notes?`، `order_timing?` asap/scheduled، `scheduled_for` للجدولة فقط. اسم العميل مطلوب حتى150 حرفًا، الهاتف مطلوب حتى20 دون افتراض تنسيق سعودي، البريد nullable email حتى255، الملاحظات حتى1000. الجدولة تتطلب ISO8601 يتضمن Z أو offset، موعدًا مستقبليًا ضمن الإعدادات وساعات الفرع. Delivery فقط تحتاج `address_uuid` أو `address`، ولا يقبل المصدران معًا. Pickup يستبعد العنوان.

4. **Pickup payload:** `{type:'pickup',customer:{name,phone,email:null},payment_method:'cash',notes:null,order_timing:'asap'}`؛ يمكن اختيار card والجدولة إن كانت مسموحة. لا branch/user/totals/address؛ الفرع مستمد من السلة.

5. **Delivery payload:** نفس بيانات التواصل والدفع مع `type:'delivery'`، وإحدى `{address_uuid:uuid}` أو `{address:{recipient_name,phone,city,district,street,building_number,floor,apartment,landmark,notes,latitude,longitude}}`. لا fee/zone/quote-id/total.

6. **Guest payload:** نفس Request؛ يثبت الوصول للسلة عبر `X-Cart-Token`. لا حساب وهمي أو user_id. الواجهة تقرأ `orders.allow_guest_checkout` من `/settings/public`، والخادم يعيد التحقق منه وقت الإرسال.

7. **Authenticated payload:** نفس Request؛ Bearer مركزي + `X-Cart-UUID` للسلة المحددة. تستخدم الواجهة UUID دائمًا لتجنب اختيار سلة أخرى أو fallback إلى آخر طلب محول عند وجود عدة سلات.

8. **Saved Address:** إرسال UUID فقط عند اختيار عنوان محفوظ. الخادم يطلب ملكية العنوان، وينسخ الحقول إلى OrderAddress. العنوان الافتراضي يظهر بوسم، ولا يُعتمد تلقائيًا كدليل صلاحية. عنوان الغير أو المحذوف يعيد404. لا user_id.

9. **Manual Address:** متاح للضيف والعميل؛ لا يُحفظ في الحساب أو localStorage. بيانات recipient/phone/city/district مطلوبة؛ بقية الحقول اختيارية بحسب Request. الإحداثيات تُرسل معًا عندما تُدخل، واشتراطها النهائي من إعدادات الخادم. حقول label/is_default لا تُرسل في Checkout.

10. **Final Cart Validation:** `PlaceOrderAction` يقفل المستخدم إن وجد والسلة والفرع، يفحص الطلب الموجود أولًا، ثم صلاحية السلة/توقيت التشغيل/النوع. يعيد التسعير والتحقق من المنتج والتصنيف والتوفر والتعيين للفرع والخيارات/تكوينها والمخزون والحدود. يجمع issues ويرفض422 قبل إنشاء الطلب. تعرض Vue الرسائل النصية والـbusiness issues المنظمة؛ لا تنفذ المحرك مرة ثانية.

11. **Final Delivery Validation:** يعاد بناء العنوان والتحقق من الملكية والإحداثيات والمنطقة والحد الأدنى وحساب رسوم التوصيل داخل Transaction. Quote السابقة تقدير وليست ضمانًا. يعاد التحقق من الفرع والإعدادات مرة أخرى قبل إنشاء Order.

12. **Pricing:** Checkout يعيد التسعير داخليًا، ولا يحتاج refresh إلزامية منفصلة. السعر الحالي للمنتج/الفرع/الخيار والترويج هو المعتمد. Backend لا يتطلب موافقة سعرية منفصلة ولا يرسل PRICE_CHANGED كشرط إنشاء؛ لذلك تعرض الواجهة تنبيه الاعتماد على السعر الحالي ثم تعرض قيمة OrderResource النهائية. Money يحسب القيم الدقيقة؛ لا ترسل Vue totals. فشل المعاملة يعيد التسعير السابق في DB. رسوم Quote وestimated_total للعرض فقط.

13. **Idempotency:** لا يدعم هذا العقد `Idempotency-Key`، ولم أضف header مخترعًا. الهوية الفعلية للمحاولة هي السلة نفسها. إعادة Checkout على السلة converted ترجع Order نفسها. تحفظ الواجهة محاولات مستقلة لكل حساب وللضيف، تتضمن معرّف السلة ونوعها وحسابها وتوقيت/طريقة الطلب فقط في sessionStorage، دون PII أو body؛ body يبقى في الذاكرة للمحاولة الحالية. بعد refresh يعاد إدخال البيانات الشخصية مع المحافظة على السلة نفسها.

14. **Double Checkout:** زر loading/disabled وقفل مشترك في CartStore، وتجميد تعديل السلة وإيقاف navigation أثناء الإرسال. الحماية الحقيقية: Transaction + lockForUpdate + unique `orders.cart_id` + إعادة الطلب الموجود. لا تنشأ سلة بديلة أثناء محاولة غير محسومة. التكرار لا يعيد حجز المخزون أو إطلاق OrderPlaced.

15. **Order Number:** `OrderNumberGenerator` يولد `SH-` + تاريخ ymd + ULID uppercase. `order_number` unique في DB. تعرضه Vue كما يعود دون توليد أو تفسير.

16. **OrderResource:** `id,order_number,type,status,order_timing,scheduled_for,payment:{method,status},customer:{name,phone,email},branch:{id,name},items,promotions,address,pricing:{subtotal,delivery_fee,discount_total,tax_total,total,currency},notes,placed_at,timeline`، cancellation عند الإلغاء وlatest_payment إذا loaded. القائمة لا تحمل items/address؛ تفاصيل الطلب تحملها. لم أخترع صورة snapshot غير موجودة.

17. **Default Order Status:** `pending` من Backend؛ الواجهة تعرض الحالة فقط ولا تعدلها.

18. **Default Payment Status:** `pending`. Cash ينشئ سجل Payment داخليًا؛ card لا يعني دفعًا ناجحًا أو وجود بوابة متكاملة. الواجهة تختار enum وتعرض method/status فقط، دون endpoints دفع.

19. **Item Snapshots:** product UUID/name/SKU، quantity، base_price/options_total/unit_price/line_total/discount_total/line_total_after_discount. category UUID/name محفوظان داخليًا لكن OrderItemResource لا يعرضهما. العرض يعتمد على Resource، ولا يستدعي المنيو أو المنتج الحالي.

20. **Option Snapshots:** `group:{id,name}`, `value:{id,name}`, `price_modifier`. الاسم والمعرّفات والزيادة نسخ وقت الإنشاء؛ لا lookup للخيارات الحالية.

21. **Address Snapshot:** recipient_name/phone/city/district/street/building_number/floor/apartment/landmark/notes/location latitude+longitude/delivery_zone_name. zone UUID محفوظ داخليًا وغير معروض. صفحة التفاصيل لا تجلب CustomerAddress الحالية لإعادة بناء الطلب.

22. **Guest Access:** `GET /api/v1/orders/{uuid}/guest` مع `X-Order-Token`. الخام opaque بطول64، مخزن hashed SHA256 في DB وله expiry configurable. GuestOrderAccess يفحص hash/طول/انتهاء/كون الطلب غير مملوك لحساب؛ الإخفاق404. UUID وحده غير كافٍ.

23. **Guest Token Storage:** token فقط، مفتاح sessionStorage لكل UUID؛ لا Order كاملة ولا هاتف أو عنوان. token لا تدخل URL/console/logs. نسخة ذاكرة عند تعذر التخزين، مع تنبيه أن refresh قد يفقد الوصول. تبقى صلاحية الخادم هي المرجع. إغلاق الجلسة يفقد الوصول المحلي عمدًا، دون نقلها إلى localStorage.

24. **Order Success Access:** `/order-success/:orderUuid` يعيد GET حقيقيًا عند الدخول/التحديث؛ العميل يستخدم authenticated endpoint، والضيف يستخدم header secret المحفوظ. وجود credential ضيف محفوظ يسمح بقراءة طلبه حتى إذا سجل دخولًا لاحقًا. `router.replace` بعد الرد الصحيح. الصفحة لا تعتمد على object في الذاكرة أو بيانات route مشتقة؛ loading/error/retry بدل نجاح وهمي.

25. **Cart After Success:** الخادم يحول السلة إلى `converted`، ولا يدعم GET بعدها. لا تستدعي Vue DELETE قبل/بعد الإنشاء. بعد OrderResource صحيحة تزيل السلة من العرض وعدد المنتجات، وتزيل token الضيف الخاصة بالسلة ومؤشر المحاولة. لا تنشئ سلة جديدة على صفحة النجاح أو تفاصيل/قائمة الطلبات؛ التهيئة عند إضافة منتج أو زيارة السلة صراحة.

26. **Cart After Failure:** تبقى السلة. 422 نهائي من Action يظهر issues ويحدث نسخة السلة دون إنشاء Order وهمية. timeout/network/5xx/رد غير صالح يترك محاولة معلقة ويمنع استبدال السلة؛ retry يستخدم نفس cart. أخطاء validation بعد محاولة غير محسومة تسمح بتصحيح البيانات مع الحفاظ على سياق المحاولة. Action-level422 بعد فحص الطلب الموجود يثبت الرفض ويسمح بالتعديل. لا نفترض عدم إنشاء الطلب بسبب انقطاع الاتصال.

27. **Customer Orders:** `GET /api/v1/orders` مع Bearer، own orders فقط، pagination `page/per_page` (حتى100). نفذت فلاتر status/type/payment_status/date_from/date_to مع server pagination. `search/branch` مقبولان في ListOrdersRequest لكن CustomerOrderQuery يتجاهلهما؛ لم أقدم UI مضللة لهما.

28. **Customer Details:** `GET /api/v1/orders/{uuid}` مع Bearer؛ own order فقط وإلا404. Frontend route `/account/orders/:orderUuid` requiresAuth، مع reload/error وحماية من الرد المتأخر عند تغير الجلسة/المسار.

29. **الملفات الجديدة:** `src/api/checkout.api.js`, `src/api/orders.api.js`, `src/utils/orderAccess.js`, `src/composables/useOrderDetails.js`, `src/components/site/OrderSnapshot.vue`, `src/views/site/CustomerOrdersView.vue`, `src/views/site/CustomerOrderView.vue`, `tests/checkout.browser.mjs`, وهذا التقرير. لا store إضافية بلا سبب؛ القفل/المحاولة داخل CartStore مشتركان لأن Header/Cart/Quote/Checkout تتعامل مع السلة نفسها.

30. **الملفات المعدلة:** CheckoutView، OrderSuccessView، stores/cart، SiteLayout، CartView، SiteHeader، AddressesView، AddressBook، router/index، `.gitignore`، و`tests/delivery.browser.mjs` لتحديث محاكاة تغير المصدر بعد تجميد حقول Checkout أثناء Quote. AddressBook أزرار غير submit صريحة؛ نموذج Checkout منفصل عن نموذج إدارة العنوان لتجنب إرسال طلب عند حفظ/اختيار عنوان.

31. **Routes:** أضيفت `account.orders` و`account.order`، وأُفعّلت `order-success/:orderUuid?` الحالية. قائمة الحساب/العناوين تشير إلى طلباتي. UUID ناقصة أو غير صحيحة تعرض خطأ دون طلب وصول عشوائي.

32. **Missing UI المكتشفة:** تأكيد فعلي، بيانات التواصل للتوصيل أيضًا، email اختياري، payment enum، notes، scheduling حسب public settings، منع guest checkout إن عطلته الإعدادات، استعادة المحاولة، بيانات وصول الضيف، النجاح مع refresh، قائمة/تفاصيل الطلبات والفلاتر.

33. **Missing UI المنفذة:** كل ما في32. استُخدمت theme/RTL/inputs/cards/buttons الحالية؛ OrderSnapshot يعاد استخدامه في النجاح والتفاصيل. قيود المستقبل وساعات الجدولة يقررها Backend؛ input يوضح توقيت الجهاز ويرسل UTC بصيغة صحيحة. لا نستنتج توفر موعد مستقبلي من إغلاق الفرع الآن.

34. **Backend-only/out-of-scope:** الأقفال والـunique constraints/stock reservations/status history initial row/events/phone normalization/estimates داخل Laravel، دون أدوات تعديلها. Promotions القائمة تطبقها عملية Checkout الموجودة؛ لا coupon management. lifecycle/cancel/kitchen/history/tracking endpoints/payments/notification/claim guest orders ليست UI لهذه المرحلة. عرض status/payment enums لا يبدأ إدارتها.

35. **Demo التي أزيلت:** رسالة Checkout preparation-only والزر الذي كان يعرض إشعارًا دون إنشاء؛ placeholder OrderSuccess واستعادة الطلب غير المفعلة؛ رسالة السلة التي كانت تقول إن الإرسال سيأتي لاحقًا. لا preview order أو fallback عند API failure. لم أزل demo الخاصة بـAdmin Orders أو الوحدات خارج النطاق.

36. **Guest Pickup Test:** نجح في المتصفح بنقل HTTP محاكى: actual Vue→Axios→Checkout→GET guest order→refresh، منع double click، header الصحيح، cart badge/token handling، وعدم إنشاء cart أخرى عند النجاح. اختبارات Laravel تنشئ Order حقيقية في DB الاختبار. البيئة الحية متاحة لكنها مغلقة؛ نجح live negative flow كما في45.

37. **Guest Delivery Test:** نجح نقل المتصفح بالعنوان اليدوي وQuote ثم Checkout، عرض fee النهائي وsnapshot وملاحظات escaped؛ Laravel يؤكد إعادة حساب الرسوم والحدود والـsnapshot الحقيقي. لا ادعاء بإنشاء Delivery Order على قاعدة البيانات الحية المغلقة.

38. **Auth Delivery Test:** نجح في المتصفح مع Bearer + exact X-Cart-UUID + address_uuid دون address body. القائمة pagination والتفاصيل المملوكة تعمل. Laravel يثبت ownership وmanual one-time address وsaved snapshots. اختبر أيضًا Auth Pickup والجدولة مع ISO UTC.

39. **Double Submit Test:** الضغط الثاني أثناء احتجاز رد Checkout لم يرسل POST إضافية؛ تعديل السلة محجوب. retry بعد فقد رد committed auth order أعاد الطلب نفسه. Backend tests تثبت unique constraint/converted replay/event once/stock reservation once/rollback. لم أسم اختبارات SQLite اختبارات concurrency حية لـMySQL.

40. **Price Change Test:** Laravel يغير سعر المنتج وسعر الفرع والخيار قبل الإرسال ويتحقق من القيم النهائية. المتصفح يعرض سعر OrderResource المختلف عن آخر Cart snapshot؛ payload بلا totals. API لا تتطلب موافقة منفصلة، وهذا موضح للمستخدم.

41. **Availability Test:** Laravel يختبر inactive/unavailable/deleted/detached/options configuration/branch flags/category/inventory/timing/closures/settings. المتصفح يعرض structured PRODUCT_UNAVAILABLE وoutside-zone بعد Quote ناجحة، مع cart preservation. live checkout422 BRANCH_CLOSED والواجهة بلا interception تعطل التأكيد فعليًا.

42. **Product Snapshot Test:** CheckoutTest ينشئ الطلب ثم يغير الاسم/السعر ويحذف المنتج، ويقرأ guest order بما يطابق response الأصلية. OrderAccessTest يثبت بقاء snapshot عند hard delete. المتصفح لا يطلب منتجًا حاليًا ويعرض الاسم وSKU والسعر الثابت.

43. **Address Snapshot Test:** CheckoutTest ينشئ saved-address order ثم يعدل/يحذف العنوان ويعدل العميل، ويقرأ Order الأصلية نفسها. اختبار المتصفح يغير saved fixture بعد الإنشاء ويستمر بعرض OrderAddress، دون إعادة جلب عنوان لبناء التفاصيل.

44. **Option Snapshot Test:** Backend يغير/يحذف group/value بعد الإنشاء ويثبت بقاء Resource. المتصفح يعرض group/value/price_modifier الثابتة، بلا lookup حالي.

45. **Backend Issues/Limits:** (أ) رمز الضيف يُرسل فقط عند إنشاء الطلب الأول؛ replay200 لا يعيده، ولا endpoint لإعادة إصداره. إذا فُقد أول response بعد commit لا تستطيع Vue استعادة raw secret من hash. تمنع إنشاء طلب ثانٍ وتعرض حد الوصول صراحة بدل نجاح مزيف؛ تحسين ذلك يحتاج Backend منفصلًا. (ب) search/branch في ListOrdersRequest لا ينفذهما CustomerOrderQuery. (ج) Quote ليست تفحص كامل ساعات التشغيل؛ Checkout يفحصها نهائيًا. (د) snapshot لاسم الفرع غير موجود؛ Resource يقرأ علاقة الفرع الحالية، بينما منتجات/خيارات/عنوان/customer/pricing snapshots فعلية. البيئة الحية الآن: branches200/settings200، الفرع BRANCH_CLOSED والجدولة false. live: create cart201/add item201/checkout422/cart GET200 active بنفس item؛ نظفت عنصر سلة الاختبار عبر API بعد التحقق. السلة الفارغة المؤقتة تنتهي وفق صلاحية Backend. لم أعدل إعدادات/مصدر Backend أو منتجاته لإجبار النجاح.

46. **Build:** `npm run build` ناجح. تحذير Rollup الموجود عن static+dynamic import لـapiError بقي تنبيه تقسيم bundle، ولا يمنع البناء أو يعكس runtime error.

47. **Console:** browser suite تتحقق من Vue warnings/runtime exceptions/console errors و404 assets؛ لا تشخيصات أو asset failures في السيناريوهات. report لا يحتوي بيانات العملاء أو credentials. ملف النتائج المحلي `.checkout-check/report.json` تجاهلته Git مع profile.

48. **المتبقي:** لا كود مطلوب متروك في نطاق هذه المرحلة. نجاح إنشاء Order على الـlive environment يحتاج فرعًا مفتوحًا/جدولة مسموحة وبيئة عميل مناسبة؛ لم أتجاوز قواعده. خطر فقد رمز الضيف عند ضياع أول response موثق كحد Backend ولا يُحل بأمان داخل Vue وحدها. لا claims بأن Vue يجعل snapshots غير قابلة لتعديل DB؛ هي تحترم snapshots التي تبنيها Laravel، ولا توجد هنا UI لتعديلها.

## BACKEND → FRONTEND COVERAGE

| Backend feature | Frontend coverage | Verification |
|---|---|---|
| Guest/auth cart checkout, pickup/delivery | CheckoutView + checkoutApi + shared cart lock | Laravel + browser fixtures؛ live رفض الفرع المغلق |
| Saved/manual address, quote/final fee | AddressBook/AddressFields + UUID/manual payload + final OrderResource | Laravel ownership/fee tests + browser |
| Customer contact/email/notes/payment enum | Forms + field422 + cash/card فقط | Backend + browser |
| ASAP/scheduled/public guest setting | settings/public controls + explicit UTC payload | OperationalAvailabilityTest + browser |
| Final availability/pricing/inventory | Backend authority + visible business issues + server totals | 137 tests ضمن النطاق + browser + live |
| Converted cart replay | Persist same cart attempt, block new cart, retry, replace route | Backend transaction/replay + network/refresh browser |
| Guest access | opaque X-Order-Token + sessionStorage + API reload | OrderAccessTest + browser |
| Immutable item/options/address/customer/pricing | Shared OrderSnapshot، بلا current-entity lookup | Backend mutation/deletion snapshots + browser |
| Customer list/details | Account routes, effective filters, pagination, owner endpoint | Backend ownership + browser |
| Internal events/locks/stock/history rows | Backend فقط | لم أضف أدوات إدارة |
| Lifecycle/history/kitchen/payments/advanced tracking/notifications | خارج المرحلة بطلب المستخدم | لم تبدأ |

## NEXT MODULE READINESS

الأساس جاهز لبدء audit وربط Order Lifecycle + Order Status History + Kitchen Queue + Customer Tracking كمرحلة مستقلة: backend routes ومفاهيم الحالة موجودة، Order API modules وعرض snapshots والتحقق من الملكية متاحة. لم أنفذ أي controls أو polling أو transitions لهذه المرحلة التالية.

جاهزية الإطلاق الحي مشروطة بتجربة تدفق إنشاء إيجابي في فرع متاح، ومعالجة/قبول حد one-time guest token عند فقد response الأولى. يجب تدقيق العقود والصلاحيات والتاريخ والتقديرات وإجراءات العميل/المطبخ في المرحلة التالية، وعدم الاعتماد على demo Admin Orders الموجودة.

## أوامر التحقق

- `php artisan test --compact --filter='CheckoutTest|OrderAccessTest|OrderTransactionTest|OperationalAvailabilityTest|InventoryOrderIntegrationTest'`: **137 passed، 815 assertions**، DB الاختبار، دون تعديل Backend.
- `node --experimental-websocket tests/checkout.browser.mjs`: actual Chrome/Vue/Pinia/Axios، HTTP fixtures منفصلة عن live checks، 12 مجموعة سيناريوهات و12 فحص مقاسات390/768/1440؛ جميع تدفقات Checkout اختُبرت أيضًا بعرض الهاتف390.
- `node --experimental-websocket tests/delivery.browser.mjs`: فحص الرجوع ناجح؛ 9 مجموعات و6 مقاسات، دون Console/asset errors.
- `npm run build`: ناجح.


