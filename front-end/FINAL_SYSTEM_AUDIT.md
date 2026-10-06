# شيشلي — Final Full-System Audit

تاريخ التدقيق: 2026-10-05. النتيجة: **NOT READY للإطلاق الإنتاجي الحالي**.

نُفذت التغييرات الممكنة في Vue باستخدام عقود Laravel الموجودة، مع الاحتفاظ بأسلوب Şişli والكروت والأيقونات والجداول والـRTL. لم تُعدّل ملفات Laravel أو تصميم HTML القديم. أُضيفت وظائف حقيقية، وليست شاشات تعتمد على بيانات تجريبية.

## 1. BACKEND INVENTORY

جرد المصدر وقراءة العقود، مع مقارنة المسارات الفعلية من `artisan route:list` واختبار API محليًا. راجعت FormRequests وResources والصلاحيات والأفعال والخدمات والموديلات والمخطط، إضافة إلى إعدادات الدفع والإشعارات والمصادقة. الجرد الآلي يقرأ الملفات كاملة؛ هذا لا يعني قراءة بشرية لكل سطر أو اختبار كل مسار كتابة حيًا.

| النوع | العدد |
|---|---:|
| سجلات مسارات API، مع دمج HEAD مع GET حيث تعرضه Laravel | 207 |
| Controllers، بما فيها Controller الأساسي | 61 |
| FormRequests | 76 |
| Resources | 54 |
| Actions | 76 |
| Services | 103 |
| Models | 42 |
| Enums | 31 |
| Policies في مجلد Policies | 0 |
| Events / Listeners / Jobs | 7 / 5 / 1 |
| Middleware | 5 |
| Migrations / Seeders | 40 / 12 |
| ملفات اختبارات PHP | 105 |
| صلاحيات فعلية | 92 |

عدم وجود مجلد Policies لا يعني غياب التفويض: Middleware وActions وServices تنفذ القيود، وتوجد `OrderPaymentPolicy` كخدمة أعمال. الإشعارات تستخدم نظام المجال الموجود، وليس Laravel Notification classes.

الأدلة: [جرد المصدر](audit/backend-inventory.json)، [المسارات مع Middleware والتفويض](audit/backend-routes.json)، [الجداول والأعمدة](audit/backend-schema.json)، [الصلاحيات](audit/backend-permissions.json)، [جرد الواجهة](audit/frontend-inventory.json). مقارنة SHA-256 تؤكد بقاء **618 ملفًا مجرودًا من Laravel دون تغيير**: [نتيجة المقارنة](audit/backend-integrity.json). هذا العدد يمثل الملفات في فئات الجرد، وليس كل ملف في المستودع.

## 2. BACKEND → FRONTEND COVERAGE

| الحالة | عدد سجلات المسارات |
|---|---:|
| COVERED | 130 |
| IMPLEMENTED_NOW | 66 |
| BACKEND_INTERNAL | 9 |
| BLOCKED_BY_BACKEND | 2 |
| MISSING داخل المسارات المجرودة | 0 |

`COVERED` تعني وجود تكامل مطابق للمصدر؛ لا تعني أن كل عملية كتابة أُعيد تنفيذها على قاعدة البيانات المحلية. `IMPLEMENTED_NOW` تشمل التكامل الجديد وتوسعة واجهات موجودة؛ العدد ليس 66 صفحة أو API جديدة. الوظائف المطلوبة التي **لا تملك API أصلًا** موثقة في القسم 6 ولا تدخل ضمن عدد المسارات الـ207.

| Module / Feature | Endpoint مختصر، تحت `/api/v1` | Frontend | Status |
|---|---|---|---|
| المصادقة والسلة والطلب النقدي والتتبّع | `/login`, `/cart/*`, `/checkout`, `/orders/*` | Auth / Cart / Checkout / Orders | COVERED |
| المنتجات والأقسام والخيارات والصور والفروع | `/admin/products/*`, `/admin/categories/*`, `/admin/branches/*`, `/admin/option-groups/*` | Catalog وواجهات التفاصيل | COVERED |
| الطلبات والمطبخ والتحصيل وسجل المدفوعات | `/admin/orders/*`, `/admin/kitchen/*`, `/admin/payments/*` | Orders / Kitchen / Payments | COVERED |
| التقارير والإعدادات والإشعارات | `/admin/reports/*`, `/admin/settings/*`, `/admin/notifications/*` | Reports / Settings / Notifications | COVERED |
| الموظفون والأدوار والصلاحيات | `/admin/staff/*`, `/admin/roles/*`, `/admin/permissions` | StaffView / RolesView | IMPLEMENTED_NOW |
| سجل النشاط | `/admin/activity-logs` | ActivityLogsView | IMPLEMENTED_NOW |
| العملاء المسجلون والضيوف | `/admin/customers/*`, `/admin/guest-customers/*` | CustomerManagementView | IMPLEMENTED_NOW |
| المخزون وحركاته وتقريره والإرجاع اليدوي | `/admin/inventory/*`, `/admin/reports/inventory`, `/admin/orders/{order}/stock/return` | InventoryView / StockReturn | IMPLEMENTED_NOW |
| العروض والكوبونات | `/admin/promotions/*`, `/promotions`, `/cart/coupon` | PromotionsView / CouponsPanel / CartCoupon | IMPLEMENTED_NOW |
| CMS وSEO والصور | `/admin/pages/*`, `/admin/banners/*`, `/admin/gallery/*`, `/home`, `/pages/{key}`, `/gallery` | CmsView / CmsHomeView / CmsPageView | IMPLEMENTED_NOW |
| الملف الشخصي وربط طلبات الضيف | `/profile`, `/profile/claim-orders` | ProfileView | IMPLEMENTED_NOW |
| الفروع العامة | `/branches`, `/branches/{branch}` | PublicBranchesView | IMPLEMENTED_NOW |
| بدء الدفع الإلكتروني، حساب/ضيف | POST `/orders/{order}/payments`, POST `/orders/{order}/guest/payments` | لا يظهر خيار بدء دفع غير مهيأ | BLOCKED_BY_BACKEND |
| الصحة وWebhook ومسارات النقل البديلة | موضحة في المصفوفة | لا تتطلب جميعها صفحة مستقلة | BACKEND_INTERNAL |

المصفوفة الكاملة، سطر لكل مسار مع Method وController وMiddleware وAuth وPermission وAudience وملف الواجهة والسبب: [Markdown](audit/endpoint-coverage.md)، [CSV](audit/endpoint-coverage.csv)، [JSON](audit/endpoint-coverage.json).

## 3. MISSING FEATURES FOUND

وجد التدقيق فجوات واجهة رغم وجود API: إدارة الموظفين والأدوار، سجل النشاط، العملاء المسجلين والضيوف وتفاصيل طلباتهم وعناوينهم، المخزون وحركاته وتقاريره والإرجاع، العروض والكوبونات، محتوى الموقع وSEO ورفع الصور، الملف الشخصي وربط طلبات الضيف، العروض والفروع العامة. نُفذت هذه الفجوات.

وجد أيضًا بيانات معاينة قديمة ومسارات CMS غير موصولة، نقص فلاتر وتحقق نماذج، خطر نتائج طلبات قديمة بعد تغيير الصفحة، payload أدوار يعيد إرسالها عند تعديل اسم الموظف، وإمكان وصول استجابة تفاصيل قديمة أثناء فتح نافذة أخرى. عولجت هذه المشكلات.

## 4. IMPLEMENTED NOW

| الملفات الأساسية | الوظائف المنفذة |
|---|---|
| `views/admin/StaffView.vue`, `RolesView.vue` | قوائم، بحث، تفاصيل، إنشاء وتعديل موظف، تفعيل وإيقاف، تعيين أدوار، عرض صلاحيات وتعديل صلاحيات الأدوار المسموحة |
| `views/admin/ActivityLogsView.vue` | سجل فعلي، بحث وفلاتر موظف وعملية ومورد وتواريخ، pagination، عرض آمن للتغيير |
| `views/admin/CustomerManagementView.vue` | تبويبات المسجلين والضيوف، فلاتر، تفاصيل، طلبات وعناوين، تفعيل وإيقاف المسجلين |
| `views/admin/InventoryView.vue`, `components/admin/StockReturn.vue` | تهيئة، إعدادات، استلام، تسوية، هدر، حركات عامة وحركات المنتج، تقرير، إرجاع مخزون الطلب الملغى بالـAPI |
| `views/admin/PromotionsView.vue`, `components/admin/PromotionEditor.vue`, `components/admin/CouponsPanel.vue`, `components/site/CartCoupon.vue` | CRUD العروض والكوبونات، نطاقات الاستهداف، تواريخ UTC، حدود الاستخدام، تطبيق وإزالة كوبون فعلي |
| `views/admin/CmsView.vue`, `constants/cmsFields.js`, `components/site/CmsSection.vue` | صفحات وأقسام موجودة، نشر ومسودة، SEO، صور، بانرات، معرض، ترتيب وتفعيل |
| `views/site/CmsHomeView.vue`, `CmsPageView.vue`, `PublicOffersView.vue`, `PublicBranchesView.vue`, `ProfileView.vue` | محتوى عام من API، عروض وفروع، حساب العميل وتعديل الاسم والبريد، ربط الطلبات عند أهلية الهاتف الموثق |
| `api/adminOperations.api.js`, `customers.api.js`, `inventory.api.js`, `promotions.api.js`, `cms.api.js` | نقل موحّد عبر عميل HTTP، عمليات مقيدة بالعقود الموجودة، والملف الشخصي ضمن Customers API |
| `components/admin/DomainFields.vue`, `DomainFilters.vue`, `composables/useServerList.js`, `useModalDraft.js` | نماذج وفلاتر وحقول موصولة، قوائم خادم، أخطاء 422، skeleton، منع إعادة الإرسال، حماية التعديلات غير المحفوظة |
| Router / Sidebar / API state / Content URLs | routes وصلاحيات فعلية، lazy loading، معالجة فشل تحميل route، إبطال النتائج القديمة، روابط محتوى آمنة |

بقي التمرير داخل المودالات مع مساحة فاصلة عن الحقول. أسلوب الكروت والأيقونات والجداول والألوان القديم محفوظ. لا يوجد حساب بديل للأسعار أو ربط وهمي بباك إند غير موجود.

## 5. BACKEND ONLY FEATURES

الـWebhook والتحقق من توقيعه، أقفال المخزون، الحجز والتحرير والبيع، idempotency الدفع والطلب، مواعيد النشر، تقييم الكوبونات، تخزين access token كـhash، عمليات queue ومحاولات الإشعارات، migrations وseeders تبقى في Laravel. Vue تعرض نتائجها وتنفذ الأوامر المسموحة فقط.

تسعة مسارات `BACKEND_INTERNAL`: `health`، payment webhook، `kitchen/orders` المتوافق، مسارا public products العامان، category show، banners العام البديل، standalone delivery quote، admin navigation. الواجهة تستهلك بدائلها المناسبة الموجودة مثل `/menu`، `/home`، `/cart/delivery/quote`، و`/admin/kitchen/orders`. توزيع عناصر القائمة القديم محلي، ويُفلتر باستخدام صلاحيات `/admin/me` الفعلية.

## 6. BLOCKED BY BACKEND

| الوظيفة | الدليل / سبب التعطل | المطلوب في Backend أو التشغيل |
|---|---|---|
| دفع إلكتروني فعلي | `back-end/config/payments.php`: providers غير مهيأة؛ POST payment موجود لكنه لا يقدم مزوّدًا صالحًا | تهيئة مزوّد فعلي وعقد قدراته واختبار redirect/webhook والتسوية؛ عدم إظهار نجاح دفع من Vue |
| OTP والتحقق من الهاتف واستعادة كلمة المرور | لا توجد routes لهذه الرحلات في `back-end/routes/api.php` | API إصدار والتحقق، rate limiting وانتهاء وإعادة إرسال وإعداد مزوّد؛ API reset password |
| ربط طلبات ضيف لحساب هاتفه غير موثق | `/profile/claim-orders` يفرض أهلية الهاتف الموثق؛ لا يوجد مسار توثيق ذاتي | توفير تحقق الهاتف؛ الواجهة الحالية تعرض الربط فقط عند الأهلية |
| تعيين الموظف لفروع | Staff FormRequests/Actions لا تقدم branch assignment | عقد ربط وفك وربط صلاحيات ونطاق بيانات؛ لا يُرسل حقل مخترع |
| إنشاء/حذف أدوار أو منح صلاحيات مباشرة لمستخدم | الموجود عرض الأدوار والصلاحيات وتعديل permissions لدور؛ الأدوار ثابتة | APIs منفصلة إذا كانت هذه العمليات مطلوبة |
| إرسال SMS / WhatsApp / Email إنتاجيًا | `config/outbound_notifications.php`: القنوات معطلة، providers SMS/WhatsApp غير مهيأة | مزوّدون، credentials، templates، queue worker واختبار وصول فعلي |
| استرجاع وصول الضيف بعد فقد استجابة checkout الأولى | replay لا يعيد إصدار access token أحادي الظهور | عقد آمن لاستعادة credential مع إثبات ملكية؛ لا تستخدم رقم الطلب كتفويض |
| دمج سلة الضيف بعد الدخول | لا يوجد cart merge endpoint | عقد دمج خادم وتعارض خيارات/فروع وكوبونات؛ السلة المجهولة محفوظة دون دمج محلي |
| نموذج مراسلة الموقع | لا يوجد contact submission endpoint | API حقيقية قبل إظهار نجاح إرسال؛ صفحة التواصل تعرض المحتوى الحقيقي فقط |
| إنشاء/حذف صفحات CMS أو أقسام جديدة | العقود الحالية للصفحات الثابتة تعرض وتعدل فقط | APIs جديدة عند الحاجة؛ الموجود تحرير ونشر الصفحات والأقسام الفعلية |

وجود اسم RateLimiter للـOTP أو خدمة داخلية لا يعادل وجود API قابلة للاستخدام. لا يوجد refund flow مكتمل إلى مزوّد خارجي يمكن اختباره بالتهيئة الحالية.

## 7. ROUTES

المسارات العامة: `/`، `/menu`، `/product/:slug`، `/cart`، `/checkout`، `/order-success/:orderUuid?`، `/track-order` وتفاصيله، `/about`، `/contact`، `/gallery`، `/offers`، `/branches`، `/login`، `/register`، `/forbidden` و404.

العميل: `/account/profile`، `/account/addresses`، `/account/orders`، `/account/orders/:orderUuid`؛ `/account` يحوّل للملف الشخصي.

الإدارة: `/admin`، products/details، categories، product-options/details، branches/details، delivery-zones، orders/details، payments/details، notifications/details، offers، customers، settings، reports. أُضيفت `/admin/staff`، `/admin/roles`، `/admin/activity-logs`، `/admin/inventory`، `/admin/cms`.

المطبخ: `/admin/kitchen` و`/admin/kitchen/:orderUuid` باستخدام صلاحيات الخادم. لا يوجد dashboard منفصل وهمي للموظف؛ الموظف يرى المسارات التي تسمح بها صلاحياته. Router guards ليست بديلًا عن authorization في Laravel.

## 8. STAFF / ROLES / PERMISSIONS

Staff APIs موجودة وتم تنفيذ UI. Role management الموجود فعليًا هو عرض الأدوار وتعديل مجموعة صلاحيات الأدوار المسموحة، وليس إنشاء أدوار جديدة. الواجهة تستخدم **92 permission فعلية** وأسماء الأدوار الموجودة: `super_admin`, `manager`, `cashier`, `kitchen`, `customer`.

صلاحيات `roles.update_permissions` وحدها لا تتجاوز قيد Super Admin. حساب Super Admin ودوره وآخر حساب فعال وقيود self-deactivation محمية في الخادم. واجهة الموظفين لا تعرض استهداف Super Admin لغير المخوّل. تغيير كلمة المرور أو الأدوار يعرض أثر إنهاء الجلسات؛ تعديل الاسم لا يعيد إرسال roles بلا تغيير.

## 9. ACTIVITY LOGS

Backend وGET `/admin/activity-logs` موجودان؛ الواجهة منفذة خلف `activity_logs.view`. المعروض: الوقت، الموظف، العملية، نوع المورد، الوصف، ومجموعة محددة من القيم القديمة والجديدة عند الحاجة. لا يوجد عرض JSON خام قد يكشف كلمات مرور أو tokens أو تفاصيل دفع. البحث والتواريخ وفلتر الموظف والقيم المسموح بها تُرسل للخادم مع pagination.

## 10. DEMO / MOCK CLEANUP

أزيلت بيانات dashboard التجريبية، قوائم المنتجات والعروض والعملاء المحلية، preview composables وpreview constants، بيانات المنيو/الصور التجريبية، جهات التواصل وأرقام الهاتف الثابتة، صفحات الموقع القديمة التي كانت تعرض محتوى أعمال محليًا، والأسعار الأصلية الوهمية ومسار ProductCard الخاص بمنتج المعاينة. لا يُعرض نجاح نموذج تواصل دون API.

لم تُحذف seeders أو fixtures من Laravel؛ هي مصدر الاختبار المحلي ومحمية ببيئة local/testing. بيانات الاختبار داخل `tests/` لا تُستورد في التطبيق. أزيلت عبارة النسخة التجريبية من قائمة الإدارة، والروابط الاجتماعية والمودالات غير الوظيفية.

## 11. LOCAL STORAGE AUDIT

| التخزين | Key | الغرض |
|---|---|---|
| localStorage | `shishli_auth_token` | Bearer token وفق عقد Sanctum الحالي، دون user profile أو صلاحيات مخزنة |
| localStorage | `shishli_cart_token` | credential السلة المجهولة |
| localStorage | `shishli.selectedBranch` | تفضيل اختيار UUID الفرع؛ الحالة والتوفر تأتي من الخادم |
| sessionStorage | `shishli_order_access:<uuid>` | credential طلب الضيف أحادي الظهور، محصور بجلسة التبويب |
| sessionStorage | `shishli_checkout_attempt` | سياق استرداد محاولة checkout مرتبط بالحساب/السلة؛ دون PII أو response الطلب |

تُزال مفاتيح البيانات القديمة `shishli.cart.v1` و`shishli.dashboard.v1` دون قراءتها. لا يوجد تخزين محلي لتاريخ الطلبات أو إجماليات وأسعار أو بيانات عملاء أو صلاحيات بوصفها مصدر حقيقة.

وجود bearer tokens في localStorage يترك خطر سرقتها عند XSS. التحول إلى HttpOnly cookies يحتاج عقد Backend وCSRF/CORS مناسبًا، ولا يجوز ادعاء حل هذا الخطر بتعديل Vue فقط.

## 12. SECURITY AUDIT

| الموضوع | النتيجة وحدود الفحص |
|---|---|
| Secrets | لم يُعثر على secret إنتاجي في مصدر الواجهة الذي جرى فحصه؛ متغير VITE الوحيد المطلوب عنوان API عام. ملفات env مستبعدة مع مثال إنتاجي دون secrets |
| Tokens | لا تُوضع credentials في الروابط أو console أو تقرير التدقيق؛ logout يطلب إلغاء token من الخادم ولا يدعي الإلغاء عند فشل الشبكة |
| PII | تُعرض البيانات اللازمة فقط في واجهات العميل والإدارة المصرح بها؛ لا business cache محلي؛ سجل النشاط لا يعرض raw payload |
| XSS / v-html | المحتوى العام نصي ويُهَرّب بواسطة Vue، والروابط تمر بتحقق البروتوكول. موضعا `v-html` المتبقيان هما SVG paths ثابتة محلية في AdminIcon وSiteIcon؛ لا تأتي من مستخدم أو API |
| Guest access | credential محفوظ sessionStorage؛ Laravel يتحقق من hash والانتهاء والملكية. اختبار credential خاطئ وحساب آخر أعاد 404، والتتبّع يعرض بيانات العميل masked |
| Admin permissions | حراس routes وأزرار فعلية باستخدام `/admin/me`، مع 403 من الخادم لا يمكن تجاوزها بإخفاء زر أو تعديل localStorage |
| IDOR | تحققت حدود ضيف/عميل فعليًا، واختبارات Laravel تغطي الملكية؛ لا يُعتبر UUID تفويضًا. لا يُدّعى penetration test شامل لكل endpoint |
| Payment / webhook | المبالغ والحالات والتوقيع والـidempotency في Laravel؛ الدفع النقدي اختُبر، ولا يوجد مزوّد إلكتروني حي لاختباره |
| OTP | غير متاح كرحلة API؛ لا واجهة تدعي إرسال أو تحقق وهمي |
| Dependencies | npm audit: 0 vulnerabilities؛ composer audit: لا advisories أو abandoned packages وقت الفحص |

يلزم فحص إعدادات HTTPS/CSP/CORS والـreverse proxy والـqueue والأسرار في بيئة النشر الحقيقية؛ الفحص المحلي لا يثبت ضبطها إنتاجيًا.

## 13. API AUDIT

`src/api/http.js` هو موضع axios الوحيد وعميل موحّد مع timeout وBearer interceptor ورفض انتهاء جلسة أحدث بسبب 401 قديم. لم يُعثر على `fetch()` مباشر في كود runtime؛ fetch في أدوات الاختبار فقط.

عنوان API يأتي من `VITE_API_BASE_URL` أو `/api/v1`. روابط Google Maps وWhatsApp وروابط license الخارجية ليست APIs أعمال بديلة. أُضيف `.env.production.example`؛ يجب proxy لجميع `/api/*` لأن current-user endpoint هو `/api/user`.

قُيد crud لكل مجال بالعمليات الفعلية: لا حذف موظف أو مخزون، ولا customer create/update وهمي، ولا coupon show غير موجود، ولا CMS page create/delete. أُصلح parsing لقائمة العروض العامة، و`branch` في home، وmultipart `_method=PATCH` للصور، وحقول discount التاريخية. بقيت بعض wrappers للنقل العام البديل كـCMS banners؛ موثقة كمسارات بديلة وليست مطالبة بصفحة إضافية.

## 14. BUSINESS LOGIC AUDIT

| المجال | Authority |
|---|---|
| Pricing والخيارات والإجماليات | Laravel cart/menu/checkout resources |
| Discounts / Promotions / Coupons | Laravel evaluation وhistorical order snapshots |
| Delivery والتغطية والرسوم | Laravel delivery quote والتحقق في checkout |
| Order transitions / Kitchen | Laravel transition actions وallowed transitions |
| Payments والتحصيل والمبالغ | Laravel payment policy/actions/providers |
| Open / Closed / closures | Laravel business hours/status؛ لا حسبة ساعات محلية بديلة |
| Notifications ومحاولات الإرسال | Laravel outbound pipeline/queue؛ الواجهة تعرض السجل وتطلب العمليات المصرح بها |
| Reports والمعاني المالية | Laravel reports؛ تنسيق الأرقام والتواريخ والرسم فقط في Vue |
| Inventory والحجز والإرجاع | Laravel transaction/locking/movement services |

Vue لا تمنح خصمًا محليًا، ولا تعيد حساب أسعار الخادم، ولا تؤكد دفعًا أو نجاح إرسال أو توفرًا دون نتيجة API.

## 15. FORMS AUDIT

قورنت نماذج Auth، products/categories/options/images، branches/catalog/delivery/business-hours/closures، addresses/checkout، order transitions/cash collection، settings/notifications/report filters، إضافة إلى Staff/RolePermissions/Promotions/Coupons/Inventory/Customers/CMS/Profile، بعقود FormRequests الموجودة.

التصحيحات الأساسية: E.164 دون افتراض دولة من رقم محلي؛ password وmax length؛ اسم الموظف 150 وحدود quantity الدقيقة .001؛ حدود الصور JPEG/PNG/WebP و5MB بحسب المجال؛ إعادة ضبط file input عند إعادة استخدام المودال حتى اختيار الملف نفسه يرسل الصورة؛ UTC لتحويلات datetime؛ الحفاظ على timestamp الأصلي غير المعدّل؛ عدم إرسال limit فارغ كـnull؛ عدم إزالة صورة حين اختير ملف بديل؛ عدم إرسال roles دون تغيير؛ scopes وأهداف UUID من pickers فعلية؛ parsing رسائل 422 النصية والكائنات؛ عدم حفظ raw JSON في CMS؛ حماية dirty forms وroute leave، وتعطيل الحفظ المتكرر أثناء الطلب.

التحقق النهائي يظل في Laravel. لا يُصنع dropdown لقيمة Enum غير موجودة. قيم الفلاتر والفرز والـpagination ترسل للخادم بدل فلترة محلية تزعم شمول كامل النتائج. نماذج الصور اختُبرت أيضًا عبر file input وmultipart في المتصفح.

## 16. RESPONSIVE / RTL

اختبار المتصفح النهائي: **26 صفحة × 3 أحجام = 78 فحصًا** بعروض 1440 و768 و390. شمل dashboard، المنتجات والأقسام والفروع والخيارات والطلبات والمطبخ والمدفوعات والإشعارات والتوصيل والعروض والعملاء والإعدادات والتقارير، صفحات التفاصيل الفعلية، Staff/Roles/Activity/Inventory/CMS والملف الشخصي.

اختبار مستقل للموقع: `/`, `/about`, `/contact`, `/gallery`, `/offers`, `/menu`, `/branches`, 404 على الأحجام الثلاثة، مع نشر محتوى اختبار مؤقت ثم استعادته. السلة والتتبّع اختُبرا أيضًا ضمن الطلب الحقيقي. فُحصت 4 نوافذ catalog، ونوافذ الموظفين والمخزون على الجوال، و16 واجهة بحث/فلتر.

لا overflow أفقي للصفحات المفحوصة، ولا خروج للمودالات من viewport، ولا runtime diagnostics أو HTTP failures في smoke الإدارة. جداول طويلة يمكن تمريرها داخليًا. أضيفت labels وfieldset/حقول مناسبة، aria للأزرار والتحميل، ووصف صور من المورد. ليست هذه شهادة accessibility كاملة أو فحص كل جهاز/متصفح.

## 17. TODO / FIXME

عولجت فجوات التكامل المعروفة، placeholders المضللة، بيانات preview، imports القديمة، نقص pagination/filters، parsing أخطاء API، race loading وroute chunk failure. لا توجد علامات TODO/FIXME متبقية في مصدر runtime المجرود وقت الفحص.

المتبقي ليس TODO مخفيًا: وظائف API غير المتاحة وإعدادات الإنتاج ومزوّدو الدفع والإشعارات واختبارات MySQL الاختيارية موثقة في القسمين 6 و21. تبقى CSS من التصميم القديم للحفاظ عليه؛ لم يُعد تصميم التطبيق أو حذف selectors على أساس تخمين بصري.

## 18. DEAD CODE

أُزيلت الملفات الـ24 التالية بعد تتبع imports واستبدال الاستخدامات:

| الملفات، بالنسبة إلى `src/` | السبب |
|---|---|
| `components/admin/catalog/CategoryFormModal.vue`, `ProductFormModal.vue` | نوافذ preview غير موصولة، البديل CatalogEditor الفعلي |
| `components/admin/offers/OfferFormModal.vue` | نموذج عروض محلي قديم |
| `components/admin/OrderStatusBadge.vue` | نسخة غير مستخدمة |
| `components/HelloWorld.vue` | starter component |
| `components/site/SiteAbout.vue`, `SiteGallery.vue` | بيانات عامة محلية بدل CMS |
| `composables/useAdminCatalogPreview.js`, `useAdminCustomersPreview.js`, `useAdminOffersPreview.js` | تخزين ومعاينات business data |
| `config/restaurant.js` | جهات تواصل ثابتة بدل public settings/branch |
| `constants/adminCatalog.js`, `adminCustomers.js`, `adminPromotions.js` | تعريفات preview القديمة |
| `data/adminDashboard.js`, `data/admin/catalog.js`, `data/catalog.js` | أعمال وأرقام وصور محلية تجريبية |
| `style.css` | CSS starter غير مستخدم |
| `views/admin/CustomersView.vue`, `OffersView.vue` | استبدلت بواجهات حقيقية |
| `views/site/AboutView.vue`, `ContactView.vue`, `GalleryView.vue`, `HomeView.vue` | استبدلت بواجهات CMS |

اختبار reachability من `src/main.js` بعد الحذف أعاد قائمة فارغة لكود JS/Vue/CSS غير الموصّل وفق imports. هذا لا يثبت أن كل CSS selector مستعمل؛ رابطا الصور في CSS والأصول البصرية يتطلبان فحصًا منفصلًا عن graph imports.

## 19. BUILD

`npm run build`: **PASS**؛ 281 modules transformed، صفر warnings وأخطاء في البناء النهائي. lazy loading خفّض أكبر chunk JavaScript إلى نحو **106.51 KB** قبل gzip. يبقى logo ومرجع التصميم صورًا كبيرة نسبيًا؛ ضغط الأصول تحسين أداء مستقل دون تغيير التصميم.

ملف `dist` ناتج بناء فقط؛ لم يُنشر الموقع أو يُغيّر إعداد Backend للإنتاج.

## 20. SMOKE TEST

| الفحص | النتيجة |
|---|---|
| PHPUnit الموجود في Laravel | 2005 ناجح، 3 skipped، 10486 assertions، إجمالي 2008 tests |
| واجهات الإدارة وresponsive | 26 صفحة، 78 فحص أحجام، 4 dialogs، 16 filters، 0 diagnostics، 0 HTTP failures |
| الميزات الجديدة والـpublic/customer/admin/kitchen | 21 checks ناجحة، 0 runtime exceptions، 0 cleanup failures |
| Guest cart/coupon/cash checkout/tracking | 11 checks ناجحة، منها خطوات Vue الفعلية، منع duplicate replay، credential خاطئ ومالك آخر 404، masking، 0 cleanup failures |
| Auth UI وCMS media | 5 checks ناجحة: تسجيل دخول صحيح وخاطئ، logout/revocation، رفع الصورة نفسها في المعرض والبانر، 0 diagnostics و0 cleanup failures |
| npm / Composer dependency audit | صفر vulnerabilities/advisories/abandoned وقت الفحص |

اختبارات PHPUnit الثلاثة skipped: `AdminStaffConcurrencyTest`, `AdminRoleMysqlValidationTest`, `InventoryConcurrencyTest` الخاصة بـMySQL الاختياري. لا يُدّعى تحقق أقفال/concurrency الإنتاجية على MySQL من اختبار SQLite.

لم تُكرر عمليات المخزون المالية غير idempotent على قاعدة محلية لمجرد زيادة عدد smoke checks، ولا عمليات إيقاف الموظفين أو تبديل صلاحيات الأدوار الإنتاجية. يغطيها suite الخادم وعقود الواجهة، بينما فحص UI للمخزون شمل القراءة والنوافذ والفلاتر. لم يُختبر مزوّد دفع خارجي أو وصول رسالة حقيقية لأنهما غير مهيأين.

أُعيدت بيانات الموظف والعميل وSEO والصفحات والأقسام وساعات العمل إلى حالتها السابقة، وحُذفت العروض والكوبونات وصور CMS المؤقتة. طلب الاختبار النقدي أُلغي، مع إبقاء سجله التاريخي وسجل النشاط الطبيعي في البيئة المحلية. يُحذف token الصادر للاختبار عبر logout. الأدلة العامة تلخّص الاختبارات دون tokens أو بيانات عملاء؛ screenshots والتفاصيل المحلية تبقى في `.reports-check` المستبعد من Git.

## 21. REMAINING ISSUES

| الشدة | المشكلة | الأثر / الإجراء |
|---|---|---|
| CRITICAL | لم يظهر عيب Critical مؤكد ضمن نطاق الفحص | هذا ليس ضمان غياب كل ثغرة أو بديل penetration test |
| HIGH | إعداد Backend الحالي local وAPP_DEBUG=true، ولم تُتحقق بيئة نشر HTTPS/proxy/secrets/CORS/queues | يمنع اعتباره إعداد إطلاق؛ تغيير إنتاجي خارج نطاق تعديل Vue |
| HIGH | صفحات CMS الفعلية الأصلية مسودات ومحتواها غير مكتمل | نشر محتوى معتمد عبر واجهة CMS الجديدة قبل فتح الموقع؛ لا demo fallback |
| HIGH، عند اشتراط الدفع الإلكتروني | providers غير مهيأة | تهيئة واختبار مزوّد ودورة webhook/payment كاملة |
| HIGH، عند اشتراط رسائل المعاملات | القنوات الخارجية معطلة أو بلا مزوّد | مزوّدون وقوالب وqueue واختبار وصول |
| MEDIUM | اختبارات MySQL concurrency الثلاثة skipped | تشغيلها على محرك وتهيئة مماثلين للإنتاج |
| MEDIUM | حفظ Bearer token في localStorage وفق العقد الحالي | XSS defense وCSP إنتاجية؛ HttpOnly يحتاج Backend changes |
| MEDIUM | غياب OTP/reset/merge/guest credential recovery/branch assignment APIs | حسب متطلبات الإطلاق؛ التفاصيل في القسم 6 |
| LOW | أصول الصور البصرية كبيرة نسبيًا | ضغط مناسب ومراجعة LCP/cache دون تغيير التصميم |
| LOW | فحص responsive على Chromium وأحجام محددة | إضافة Safari/Firefox وأجهزة فعلية وفحص accessibility أوسع عند اعتماد منصة النشر |

## 22. FINAL PRODUCTION READINESS

**NOT READY** للبيئة الحالية. البناء ناجح والتكامل الممكن من جهة Frontend نُفذ واختُبر، لكن حالة CMS وإعدادات التشغيل الحالية وبعض العقود/المزوّدين المطلوبة لا تبرر إعلان جاهزية إنتاجية.

مسار الإطلاق: نشر محتوى حقيقي عبر CMS، ضبط بيئة إنتاج Laravel والـproxy وHTTPS والـqueue، تهيئة مزوّدي الدفع والإشعارات المطلوبة أو اعتماد نطاق إطلاق نقدي صراحة، تنفيذ APIs الناقصة المطلوبة للأعمال، تشغيل فحوص MySQL الاختيارية، ثم smoke في staging مماثل للإنتاج. هذه الخطوات موثقة ولم يُعدّل Backend لتنفيذها ضمن هذه المرحلة.
