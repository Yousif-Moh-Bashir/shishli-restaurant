# تحديد عنوان التوصيل في Shishli

## التدفق المنفذ

يختار العميل عنوانًا محفوظًا، أو يطلب موقعه الحالي، أو يفتح الخريطة، أو يكتب العنوان يدويًا. لا يُطلب إذن الموقع عند فتح الصفحة. الخريطة تظهر داخل نموذج العنوان وتسمح بتحريك الدبوس والنقر واختيار منتصف الخريطة بالزر، دون تغيير تصميم بقية الموقع.

عند اختيار نقطة جديدة تُرسل الإحداثيات إلى خدمة وصف الموقع بعد تأخير 500ms. تُلغى الطلبات القديمة وتُتجاهل نتائجها. تتعبأ المدينة والحي والشارع عند توفرها، وتظل قابلة للتعديل. لا تُملأ بيانات المبنى والشقة والدور تلقائيًا. تُحفظ الإحداثيات كأرقام في Vue وتُرسل بعقد Laravel الحالي، ولا تُعرض كحقول تقنية أو تحفظ في localStorage.

زر تأكيد الموقع يطلب عرض التوصيل من Laravel. تغيير النقطة أو العنوان أو السلة يلغي العرض السابق. لا يمكن إرسال طلب توصيل قبل نجاح العرض واستكمال الحقول المطلوبة. العنوان خارج النطاق يتيح اختيار موقع آخر أو الاستلام من الفرع عند توفره. التعذر في وصف الموقع لا يحذف الإحداثيات ولا يمنع الاستكمال اليدوي للمدينة والحي.

## Backend endpoints المستخدمة

جميع المسارات أدناه تحت `/api/v1`:

- `GET /branches` و`GET /branches/{uuid}` و`GET /branches/{uuid}/status`.
- `GET /settings/public`.
- `GET /cart` ومجموعة عمليات السلة الموجودة، دون تغيير عقدها.
- `POST /cart/delivery/quote`: يرسل `address_uuid` أو `address` فقط؛ تتم قراءة السلة وإعادة تسعيرها في Laravel.
- `POST /checkout`: عقد الطلب الحالي، دون إرسال إجمالي أو رسوم محسوبة في Vue.
- `GET /addresses` و`POST /addresses`.
- `GET /addresses/{uuid}` و`PATCH /addresses/{uuid}` و`DELETE /addresses/{uuid}`.
- `PATCH /addresses/{uuid}/default`.
- إدارة المناطق الحالية: `GET/POST /admin/branches/{branch}/delivery-zones` و`GET/PATCH/DELETE /admin/branches/{branch}/delivery-zones/{uuid}`؛ بقيت الواجهة القائمة لأنها تغطي وظائف Laravel.

## حقول العنوان الفعلية

`recipient_name`, `phone`, `city`, `district`, `street`, `building_number`, `floor`, `apartment`, `landmark`, `notes`, `latitude`, `longitude`.

العناوين المحفوظة تدعم أيضًا `label` و`is_default`. يستخدم الضيف بيانات الاسم والجوال المدخلة في التواصل؛ لا يضطر إلى حفظ عنوان في حساب. يُعرض `postalCode` و`formattedAddress` من خدمة وصف الموقع مؤقتًا عند توفرهما، ولا يُرسلان كحقول جديدة لأن Laravel لا يدعمهما. لا توجد migrations أو APIs أو Models جديدة.

## قواعد التوصيل

`CartDeliveryQuoteController` → إعادة تسعير السلة → `DeliveryAddressData` → `DeliveryService::calculateDelivery` → مطابقة مناطق الأحياء أو نصف القطر → `DeliveryQuoteResource`.

`PlaceOrderAction` يعيد التحقق والتسعير عند إنشاء الطلب. الفرع هو فرع السلة؛ لا يوجد اختيار تلقائي لأقرب فرع في العقد الحالي. يدعم Laravel التحقق من نطاق الإحداثيات ودقة التخزين بسبع منازل عشرية. المدينة والحي مطلوبان حتى عند استخدام الإحداثيات، لذا يجب استكمالهما إذا لم يوفرهما المزود. الإدارة تدعم الأحياء والمركز ونصف القطر والرسوم والحد الأدنى والتوصيل المجاني والأولوية والمدة والتفعيل والفرع؛ لا يدعم النظام polygons ولم تُضف إدارة لها.

## المزود والإعدادات

Leaflet 1.9.4 هو واجهة الخريطة، مع OpenStreetMap tiles افتراضيًا. يُحمّل مكوّن الخريطة ومكتبة Leaflet عند فتح الخريطة فقط. لا تعتمد قواعد التوصيل على Leaflet.

`src/services/geocodingService.js` يعزل مزود Reverse Geocoding. المزود الافتراضي Nominatim، مع طلبات غير متزامنة قابلة للإلغاء ومهلة انتهاء وذاكرة مؤقتة داخل الصفحة محدودة بـ20 نتيجة وتباعد 1100ms بين الطلبات. لا تُستخدم Axios المعتمدة على جلسة العميل مع المزود الخارجي، ولا تُرسل إليه بيانات الاسم والجوال أو رموز المصادقة.

متغيرات عامة اختيارية، موثقة في `.env.example`:

```dotenv
VITE_MAP_TILE_URL=https://tile.openstreetmap.org/{z}/{x}/{y}.png
VITE_MAP_ATTRIBUTION=&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>
VITE_GEOCODING_REVERSE_URL=https://nominatim.openstreetmap.org/reverse
```

يمكن تعيين `VITE_GEOCODING_REVERSE_URL=disabled` لإيقاف وصف الموقع مع استمرار الخريطة والإدخال اليدوي. البديل يجب أن يدعم عقد Nominatim أو يُضاف adapter إلى الخدمة نفسها. تغيير البيئة يتطلب إعادة تشغيل Vite أو إعادة البناء.

لا توجد مفاتيح مطلوبة في الإعداد الافتراضي. المفاتيح السرية يجب أن تبقى في Laravel عند إضافة مزود يحتاجها. لا تستخدم الخادم العام Nominatim لموقع كثيف الزيارات: حد الطلبات العام يخص التطبيق كله، والتباعد داخل الصفحة لا ينظم الطلبات بين المستخدمين. للإنتاج كثيف الاستخدام اضبط endpoint لخدمة مخصصة أو مزود مناسب، وكذلك tiles حسب سعته وشروطه. الخدمة العامة لا تضمن التوفر أو دقة أسماء الأحياء.

مراجع المزود: [Leaflet](https://leafletjs.com/reference.html)، [Nominatim policy](https://operations.osmfoundation.org/policies/nominatim/)، [OSM tile policy](https://operations.osmfoundation.org/policies/tiles/).

## اختبار الموقع والجوال

1. شغّل الواجهة مع API الحالي؛ ضع منتجًا في السلة واختر التوصيل.
2. اضغط «تحديد موقعي الحالي» واسمح بإذن الموقع. تحقق من ظهور الدبوس، ثم حرّكه لتصحيح النقطة.
3. راجع المدينة والحي وأدخل تفاصيل الوصول، ثم أكد الموقع وتحقق من رسوم Laravel قبل تأكيد الطلب.
4. ارفض الإذن وأعد التجربة: يجب أن تظهر رسالة عربية وخيار الخريطة والنموذج اليدوي.
5. على الجوال افتح عنوان تطوير HTTPS موثوقًا، مع API accessible من الجوال وإعداد CORS الحالي. `localhost` على الجوال يشير للجوال نفسه، لا لجهاز التطوير.
6. عند فتح `http://192.168.x.x:5173` قد يمنع المتصفح GPS؛ يبقى الاختيار بالنقر على الخريطة والإدخال اليدوي متاحين. الإنتاج يجب أن يعمل عبر HTTPS. لا توجد تعديلات لتجاوز حماية المتصفح.

## الملفات

جديد: `MapLocationPicker.vue`, `useAddressLocation.js`, `geocodingService.js`, `utils/location.js`, `tests/address-map.browser.mjs`, وهذه الوثيقة.

معدل: `CheckoutAddress.vue`, `AddressFields.vue`, `AddressBook.vue`, `CheckoutView.vue`, `package.json`, `package-lock.json`, `.env.example`, `tests/checkout-location.mjs`, `tests/checkout.browser.mjs`, `tests/delivery.browser.mjs`.

## نتائج التحقق

- `npm run build`: ناجح؛ Leaflet في chunk منفصل، لا يُحمّل في الصفحات العامة غير المحتاجة للخريطة.
- `node tests/checkout-location.mjs`: ناجح؛ الإذن والنطاق والتعذر والمهلة والنتائج المتأخرة وحماية النص المكتوب يدويًا.
- `node --experimental-websocket tests/address-map.browser.mjs`: ناجح باستخدام Chrome وtransport fixtures؛ GPS والخريطة والنقر وتعديل الموقع ورفض الإذن وعدم الدعم وفشل وصف العنوان والعرض والرسوم وخارج النطاق و422 وفشل الشبكة والطلب والعناوين المحفوظة CRUD/default والاستلام. المقاسات: 320،360،375،390،412،430،1280.
- `tests/checkout.browser.mjs`: ناجح؛ اختبارات الطلب والدفع النقدي/الشبكة والمصادقة والعناوين المحفوظة ومنع التكرار والتعافي من فقد الاتصال و422/404/429 وعرض الاستلام. لا توجد أخطاء console أو assets في السيناريوهات المختبرة.
- `tests/delivery.browser.mjs`: ناجح؛ إدارة المناطق والأحياء ونصف القطر والتفعيل والحذف والصلاحيات ومطابقة عقد العرض والعناوين المحفوظة. لا توجد أخطاء console أو assets.
- Laravel: `php artisan test --compact --filter='CustomerAddressTest|DeliveryZoneTest|DeliveryQuoteTest|CartDeliveryQuoteTest|CheckoutTest|CartTest'`: **166 passed،1032 assertions**.
- القراءات الحية `/branches` و`/settings/public` ناجحة. لم يُنشأ طلب ناجح حي لأن الفرع مغلق والطلبات المجدولة متوقفة؛ نجاح الطلب في المتصفح هنا مثبت بالمحاكاة واختبارات Laravel، وليس ادعاء بإجراء طلب حي.

لم يُختبر GPS فعلي على هاتف أو شهادة HTTPS فعلية؛ يُختبر ذلك بخطوات الجوال أعلاه. وصف الموقع وصور الخريطة في الاختبار معترضتان ببيانات نقل، فلا تثبت تلك الاختبارات تغطية المزود الخارجي لأحياء تبوك أو توفره الفعلي. لا توجد تغييرات Backend في هذا التنفيذ.

## BACKEND → FRONTEND COVERAGE

| الوظيفة | الحالة | ملاحظات |
|---|---|---|
| Addresses | COVERED | الحقول الفعلية وعرض validation |
| Geolocation | COVERED | إذن عند الضغط فقط، انتظار وتعذر ومهلة |
| Map selection | COVERED | نقر، سحب الدبوس، اختيار المنتصف |
| Reverse geocoding | COVERED | تعبئة أفضل جهد، إلغاء الطلبات القديمة، fallback |
| Delivery zones | COVERED | قواعد Laravel دون حسابها في Vue |
| Delivery quote | COVERED | عرض جديد لكل تغيير، رسوم الخادم |
| Saved addresses | COVERED | إضافة وتعديل وحذف وافتراضي واختيار |
| Guest address | COVERED | موقع أو خريطة أو يدوي دون حساب |
| Checkout | COVERED | عقد الطلب الحقيقي وإعادة تحقق Laravel |
| Admin delivery management | COVERED | الإدارة القائمة تغطي الوظائف الفعلية |

لا يُعرض داخليًا أي معرف قاعدة بيانات أو سر أو حساب تسعير. الرمز البريدي والعنوان المنسق ليسا حقولًا محفوظة في العقد الحالي، واختيار الفرع تلقائيًا وpolygons خارج وظائف Backend الحالي.
