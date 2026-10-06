# Customer Addresses + Delivery Integration

## التقرير والعقد الفعلي

1. **ملفات Backend المفحوصة:** routes/api.php؛ CustomerAddressController؛ Admin/DeliveryZoneController؛ DeliveryQuoteController وCartDeliveryQuoteController؛ Store/UpdateCustomerAddressRequest، Store/UpdateDeliveryZoneRequest، DeliveryQuoteRequest، CartDeliveryQuoteRequest، CheckoutRequest؛ CustomerAddressResource، DeliveryZoneResource، DeliveryQuoteResource، BranchResource، BranchAvailabilityResource؛ CustomerAddress، DeliveryZone، DeliveryZoneDistrict، Branch؛ Actions/Addresses وActions/Delivery؛ CustomerAddressWriter، DeliveryZoneWriter، DeliveryService، DeliveryAddressData، GeoService، AddressNormalizer، BranchAvailabilityService/Data، RestaurantSettingsService؛ DeliveryZoneType وDeliveryIssueCode؛ RolePermissionSeeder؛ migrations customer_addresses وdelivery_zones؛ اختبارات CustomerAddressTest وDeliveryZoneTest وDeliveryQuoteTest وCartDeliveryQuoteTest وحقول قدرات الفرع القائمة. قواعد الملكية في route binding وCustomerAddressWriter، ولا توجد سياسة مستقلة لازمة للواجهة.

2. **Address endpoints:** تحت /api/v1: GET/POST /addresses؛ GET/PATCH/DELETE /addresses/{UUID}؛ PATCH /addresses/{UUID}/default. تتطلب Sanctum Bearer. List يعيد pagination ثابتة 20 عنصرًا؛ نرسل page فقط، دون اختراع بحث أو مرشحات. الملكية تأتي من request user؛ عنوان مستخدم آخر يعيد 404.

3. **الحقول الفعلية:** id في Resource هو UUID؛ label، recipient_name، phone، city، district، street، building_number، floor، apartment، landmark، notes، is_default. Resource يعيد location.latitude/location.longitude بينما الطلب يرسل latitude/longitude مباشرة. لا user_id ولا معرف داخلي في الطلب.

4. **Validation:** recipient_name مطلوب حتى 150، phone مطلوب حتى 20 دون قاعدة هاتف سعودي خاصة، city/district مطلوبان حتى 100؛ label 100، street 200، building_number/floor/apartment 50، landmark 255، notes 1000؛ الإحداثيات رقمية ضمن ±90/±180، وتُرسل أو تُمسح معًا؛ is_default Boolean. Update جزئي لكن Writer يتحقق من الزوج النهائي. الواجهة تحسن UX وتعرض 422؛ Laravel هو الحكم.

5. **العنوان الافتراضي:** أول عنوان يصبح افتراضيًا حتى عند إرسال false. تعيين افتراضي يلغي افتراضية البقية داخل transaction مع lock على المستخدم. حذف الافتراضي soft-delete ويعين أحدث عنوان باقٍ افتراضيًا إن وجد. UI يعرض Badge وAction ويعيد تحميل القائمة؛ لا ينفذ القاعدة محليًا.

6. **الضيف:** لا يستخدم Saved Addresses. عنوان يدوي بالحقول الحقيقية جاهز للـCheckout لاحقًا؛ Quote تتلقى subset المدينة/الحي/الإحداثيات فقط. لا حفظ للعناوين أو الهاتف أو الإحداثيات في localStorage، ولا طباعة لها في Console. Browser Geolocation يبدأ فقط بزر صريح، ويظل الإدخال اليدوي متاحًا. إعادة تحميل Checkout تمسح المسودة الشخصية والـQuote عمدًا.

7. **Zone endpoints:** GET/POST /admin/branches/{branch UUID}/delivery-zones؛ GET/PATCH/DELETE /admin/branches/{branch UUID}/delivery-zones/{zone UUID}. Scope bindings تمنع استخدام منطقة من فرع مختلف. List يعيد pagination 20، ترتيب priority تنازليًا ثم ID. Delete هو soft-delete؛ التفعيل/التعطيل PATCH is_active، ولا endpoint جديد مخترع.

8. **حقول المنطقة:** name، type، is_active، delivery_fee، minimum_order، free_delivery_threshold، estimated_min_minutes، estimated_max_minutes، priority؛ radius center_latitude/center_longitude/radius_km؛ districts مصفوفة أسماء. Resource يعيد radius متداخلًا للنوع radius، وdistricts للنوع district؛ الطلب يرسل الإحداثيات/radius_km مباشرة.

9. **الأحياء:** DeliveryZoneDistrict أبناء للمنطقة، مع district_name وnormalized_name داخلي. لا قائمة عامة ولا UUID حي في العقد. الإدارة تستبدل القائمة عبر طلب المنطقة؛ الواجهة تقبل حيًا في كل سطر. العميل يدخل اسم المدينة/الحي، لأن هذا هو العقد الفعلي، دون قائمة أسماء ثابتة.

10. **المطابقة المدعومة:** district وradius فقط؛ لا polygons أو postal code أو map editor.

11. **Radius:** المسافة المباشرة great-circle/Haversine بواسطة GeoService؛ المطابقة تشمل الحد distance <= radius_km دون تقريب قبل المقارنة. نطاق 0.01..999999.99 كم بمنزلتين؛ المركز يتطلب الإحداثيين، والصفر صحيح. لم تضف الواجهة حساب مسافة أو مكتبة خرائط أو API key.

12. **قدرات الفرع:** supports_pickup، supports_delivery، accepts_orders، is_active في BranchResource. التحرير موجود بالفعل في إدارة الفروع. Checkout يستخدم هذه البيانات وCartResource.availability.pickup/delivery.available؛ لا defaults true.

13. **الحالة التشغيلية:** CartResource.availability يغطي إعدادات المطعم والصيانة وساعات العمل والإغلاق وإتاحة نوع الاستلام. يعرض السبب ويمنع التقدم عند عدم قبول الطلبات. عنوان فرع الاستلام من BranchResource.location. فرع غير متاح/غير معروف لا يسمح بافتراض طريقة استلام.

14. **Quote المختارة:** POST /cart/delivery/quote. الضيف X-Cart-Token، المسجل Bearer مع X-Cart-UUID من Cart Store؛ Accept application/json وJSON عبر النقل المركزي. Headers السلة لا تنتقل إلى API العناوين أو المناطق.

15. **Quote request:** إما {address_uuid: UUID} للمسجل، أو {address:{city,district,latitude,longitude}}. مصدر واحد فقط. لا branch_uuid ولا subtotal ولا total ولا fee ولا zone من المتصفح. يوجد POST /delivery/quote مستقل يتطلب subtotal من العميل؛ لم يُستخدم لأن Quote السلة أكثر ملاءمة وموثوقية هنا.

16. **Quote response:** is_estimate، available، zone{id,name} أو null، subtotal، current_subtotal، delivery_fee، estimated_total، minimum_order، minimum_order_met، remaining_amount، free_delivery_applied، estimated_delivery{min_minutes,max_minutes}، distance_km، issue{code,message} أو null. لا quote ID/token/expires_at؛ لا تُخترع صلاحية زمنية دائمة. نتيجة محلية في Checkout فقط وتُعاد عند الحاجة.

17. **Eligibility:** DeliveryService يتحقق من حالة الفرع ودعم التوصيل وإعداداته وصحة العنوان والمنطقة والحد الأدنى. Vue لا يقرر نطاق الحي أو نصف القطر؛ يعرض available/issue. Network failure يعني «تعذر التحقق»، وليس «خارج النطاق».

18. **Fee:** Laravel يأخذ delivery_fee من المنطقة، أو 0 عند بلوغ free_delivery_threshold حسب إنفاق الطعام بعد الخصم. UI يعرض القيمة، ولا يجمع المبالغ أو يحسب قاعدة توصيل. estimated_total يعرض القيمة القادمة من API.

19. **اختيار المنطقة:** مناطق الفرع النشطة فقط؛ أولوية تنازلية، ثم type (district قبل radius عند التعادل)، ثم ID تصاعدي. مطابقة district تشترط مدينة الفرع واسم الحي بعد trim/case/space normalization، دون fuzzy matching. لا priority engine في Vue.

20. **Minimum:** موجود؛ الأكبر بين minimum_order للمنطقة وorders.minimum_amount العام. الحساب قبل الخصم. Resource يعطي remaining_amount؛ نعرضه دون حسابه.

21. **Free threshold:** موجود وقابل للإدارة/الإزالة null. لا يقل عن minimum_order للمنطقة؛ تتحقق Laravel. الحساب بعد الخصم.

22. **ETA:** حد أدنى/أقصى اختياري بالدقائق من المنطقة، يُعرض إن أعادته Quote. لا ETA مخترعة ولا اعتبار distance_km مسافة قيادة.

23. **Permissions:** delivery_zones.view/create/update/delete. Sidebar وRoute يحتاجان view، والأزرار لكل عملية تحتاج صلاحيتها. المستخدم يحتاج branches.view لتعداد الفروع الإدارية بما فيها المتوقفة؛ إن لم يملكها تستخدم الواجهة public branches النشطة دون التحايل على Permission. يمكن فتح فرع محدد من رابط تفاصيل الفرع مع صلاحياته. ملكية العناوين ليست Admin permission.

24. **الملفات الجديدة:** src/api/addresses.api.js، src/api/delivery.api.js، src/constants/addressFields.js، src/composables/useDeliveryQuote.js، src/components/site/AddressFields.vue وAddressBook.vue، src/views/site/AddressesView.vue، src/views/admin/DeliveryZonesView.vue، tests/delivery.browser.mjs، هذا التقرير.

25. **المعدلة:** src/stores/cart.js (Quote ضمن قفل mutations ثم GET لمزامنة reprice)؛ src/views/site/CheckoutView.vue؛ src/router/index.js؛ src/constants/adminNavigation.js؛ src/components/site/SiteHeader.vue؛ src/views/admin/BranchDetailsView.vue؛ src/assets/css/site.css؛ .gitignore للآثار المحلية للاختبار. لا تغييرات على Backend source أو dependencies أو HTML القديم.

26. **Routes:** /account يعيد إلى /account/addresses؛ /account/addresses محمي requiresAuth ضمن SiteLayout الحالي؛ /admin/delivery-zones محمي requiresAdmin + delivery_zones.view ضمن AdminLayout نفسه.

27. **Sidebar:** «مناطق التوصيل» حسب Permission. رابط من تفاصيل الفرع يفتح المناطق لذلك الفرع. رابط عناويني في SiteHeader للكمبيوتر والمحمول.

28. **Missing UI المكتشفة:** لا إدارة Saved Addresses ولا Zones ولا structured delivery address ولا Cart Quote، مع وجود الوظائف كاملة في Backend. النموذج القديم طلب موقعًا تلقائيًا وكان يعتمد عنوانًا نصيًا وقيود هاتف محلية مختلفة عن العقد.

29. **Missing UI المنفذة:** List/Create/Show/Edit/Delete/Default للعناوين مع Confirmation وPagination؛ اختيار عنوان محفوظ وتحريره أو إدخال عنوان يدوي؛ CRUD المناطق مع type switching، قائمة الأحياء، radius، التفعيل/التعطيل، priority/minimum/free threshold/ETA؛ Quote loading/errors/eligibility/fee/summary؛ ضبط طرق الاستلام حسب الفرع ورفض Quote القديمة.

30. **غير المعروض:** normalized_name، IDs الرقمية، generated ownership keys، DB locks وSoft-delete metadata؛ تفاصيل داخلية لا تحتاج تحرير مستخدم. Polygon/قائمة أحياء عامة غير موجودتين. الإعدادات العالمية خارج نطاق الوحدة؛ الحالة التشغيلية تُستهلك من CartResource. Quote العامة المستقلة لم تُستخدم لتجنب subtotal موثوق من العميل. Coupons/Orders/Payments/Notifications لم تبدأ.

31. **Demo المزال:** استُبدلت Checkout preparation القديمة بعنوان نصي وقاعدة هاتف وتجهيز محلي بنموذج العقد الحقيقي وQuote API. لم توجد قائمة Demo مستقلة للعناوين/المناطق تستلزم حذف بيانات أخرى. لم تُحذف Demo الوحدات الأخرى.

32. **Checkout الآن:** Pickup لا يطلب عنوانًا؛ Delivery للمسجل يدعم Saved Address أو Manual، وللضيف Manual فقط. زر تحقق صريح للعناوين اليدوية، وQuote عند اختيار عنوان محفوظ. يعرض رسومًا وإجماليًا تقديريًا من الخادم ومدة/حدًا أدنى/سبب عدم الإتاحة. التحقق يعيد تسعير السلة على الخادم لذلك يعقبه GET للسلة. تغير العنوان/النوع/السلة/الفرع/الجلسة يبطل العرض، والاستجابات المتأخرة تُرفض. زر متابعة preparation فقط؛ لا POST /checkout، لا طلب ولا دفع. Prepared address contract متاح لمرحلة Checkout التالية.

33. **Live:** أُعيد تشغيل Laravel وفُحص GET /branches. النتيجة الحية كانت HTTP 500 بسبب رفض اتصال MySQL، ومسجلة في .delivery-check/report.json. لا تُنسب اختبارات النقل المحاكى إلى Live CRUD. لم تُعدل DB/config لإخفاء العطل.

34. **Saved Address:** اختبارات Backend تغطي الضيف/الملكية/CRUD/default/حذف الافتراضي/زوج الإحداثيات. اختبار المتصفح ينفذ controls العناوين فوق نقل محاكى متوافق مع Resource؛ ليس إثبات Live DB.

35. **Guest:** اختبار المتصفح يملأ النموذج ويستخدم Quote السلة، دون Saved Address API أو localStorage شخصي أو طلب موقع تلقائي. Backend tests تغطي token والضيف والعنوان اليدوي.

36. **Eligible:** Backend tests تغطي الحي المطابق وداخل radius والحد الأدنى والتوصيل المجاني. المتصفح يعرض available والرسوم/estimated_total من fixture response، دون frontend matching.

37. **Outside zone:** Backend tests تغطي حيًا/مدينة غير مطابقين وخارج radius. المتصفح يفصل رسالة outside عن فشل الشبكة ويمنع المتابعة.

38. **Branch/cart change:** Quote تُبطل بحسب branch/cart context ومحتويات Resource، مع رفض استجابة متأخرة عند تغيير مصدر العنوان. اختبارات Backend تغطي foreign-branch zones وscope bindings. اختبار المتصفح يغطي تغير Cart وتعطيل delivery وإغلاق الفرع. نتائج Live branch transition غير مصدقة عند تعطل DB.

39. **Backend issues/limits:** اتصال MySQL المحلي قد يرفض الوصول (سبق أن تسبب في 500). لا public districts endpoint وهذا ليس سببًا لاختراع قائمة. DeliveryService الخاصة بالـQuote تتحقق من switches الأساسية، لكنها لا تستدعي BranchAvailabilityService لفحص الصيانة/الساعات/الإغلاق المؤقت؛ لذلك Quote وحدها قد تختلف عن الحالة التشغيلية. Checkout preparation تمنع التقدم عبر CartResource.availability/can_checkout أيضًا. كذلك Quote تعيد التسعير قبل البحث عن العنوان، حتى إذا عاد العنوان 404؛ هذا side effect موجود ونُبه له في UX. لا تغيير Backend في هذه المهمة.

40. **Build:** npm run build ناجح؛ التحذير السابق INEFFECTIVE_DYNAMIC_IMPORT متعلق باستيراد apiError في CatalogEditor/CatalogList ولا توجد أخطاء Build.

41. **Console/Responsive:** الاختبار يسجل Runtime exceptions وconsole warning/error و404/غيرها للـassets خارج API، ويختبر مناطق Admin ونموذج العنوان بعروض 390/768/1440. اجتازت سيناريوهات المتصفح جميعها: diagnostics=[] وassetFailures=[]، دون Vue warnings أو Runtime/Console errors أو assets 404 في النطاق المختبر. نجحت عروض 390/768/1440 لكل من Admin zones وCustomer address. النتيجة في تقرير المتصفح المحلي؛ لا يُفترض أن كل صفحات Admin والوحدات الأخرى اختبرت.

42. **المتبقي:** اعتماد قبول Live يتطلب قاعدة البيانات متاحة وإعادة سيناريوهات Customer/Guest/Admin Quote/CRUD على البيانات الحقيقية. لا ادعاء اكتمال final Checkout أو Order creation. لا تكامل merge cart جديد في هذه المرحلة.

## BACKEND → FRONTEND COVERAGE

| Backend feature | الواجهة المنفذة |
|---|---|
| Customer Address CRUD/default/ownership | صفحة العناوين وAddressBook، Pagination و422 وConfirmation |
| Guest manual checkout address | AddressFields بعقد Checkout، دون حفظ شخصي |
| District/Radius zones CRUD/type switch | Admin zones table/form، أسماء الأحياء وإعدادات radius |
| Activation/priority/minimum/free threshold/ETA | حقول وأزرار حقيقية، Laravel يقرر القواعد |
| Branch pickup/delivery/operational state | طرق استلام متاحة فقط، عنوان فرع الاستلام وسبب الإغلاق |
| Cart delivery quote/reprice | Cart headers الصحيحة، إعادة تحميل Cart، server fee/estimated_total |
| Quote invalidation/concurrency/errors | مصدر العنوان + Cart/Branch/Auth، رد متأخر مهمل، network != outside |
| Backend internal matching/security | لا محرك توصيل Vue أو ownership IDs أو fee موثوق محليًا |

## NEXT MODULE READINESS

الواجهة أصبحت مهيأة للـCheckout + Order Creation + Immutable Order Snapshots من حيث العنوان والفرع وQuote، لكن Quote تقديرية والـCheckout النهائي يجب أن يعيد validation بالكامل. صلاحية الانتقال المعتمدة تحتاج إنهاء التحقق الحي بعد إتاحة MySQL ومراجعة حد الحالة التشغيلية في DeliveryService. لم تُبدأ المرحلة التالية.

## أوامر التحقق

- php artisan test --compact --filter='CustomerAddressTest|DeliveryZoneTest|DeliveryQuoteTest|CartDeliveryQuoteTest': 88 passed, 395 assertions.
- npm run build: ناجح بعد التعديلات النهائية.
- node --experimental-websocket tests/delivery.browser.mjs: ناجح، 9 مجموعات سيناريوهات نقل محاكى و6 فحوص تجاوب. Live GET /branches أعاد 500؛ لم تنفذ عمليات CRUD حية.
- تقرير المتصفح المحلي .delivery-check/report.json لا يحتوي عناوين/هواتف/إحداثيات حقيقية أو tokens.

