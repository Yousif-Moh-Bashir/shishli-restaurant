# Dashboard / Reports Real API Integration

هذه المرحلة تخص Dashboard والتقارير فقط. لم تُعدّل ملفات Laravel، ولم يبدأ ربط Settings أو CMS أو Restaurant Operations. لا توجد بيانات عرض تجريبية أو حساب تجميعات أعمال في واجهات التقارير.

النتيجة النهائية: نجح `ReportsTest` بـ68 اختبارًا و306 assertions، ونجح اختبار المخزون المستهدف بـ21 assertion؛ مجموعهما 69 اختبارًا و327 assertion في تشغيلين. نجحت 30 حالة متصفح محددة و45 تحققًا من العرض على 390/768/1440، بالإضافة إلى عرض Dashboard والتقارير الـ13 عبر API الحقيقية وتنزيل CSV مصادق عليه في المتصفح. لا console warnings/runtime errors/404 assets مسجلة. البناء النهائي ناجح.

## العقود والميزات — البنود 1–30

1. **ملفات Backend المفحوصة:** `routes/api.php`؛ `Http/Controllers/Api/V1/Admin/{DashboardController,ReportController,InventoryController,AdminSessionController}.php`؛ `Http/Requests/Api/V1/Admin/{ReportFilterRequest,InventoryFilterRequest}.php`؛ جميع ملفات `Services/Reports/`: `DashboardService`, `ReportService`, `ReportQuery`, `ReportFilterData`, `ReportMath`, `SalesReportService`, `CatalogReportService`, `AudienceReportService`, `PaymentReportService`, `OperationsReportService`, `InventoryReportService`, `CsvReportExporter`. وكذلك `Services/Admin/{AdminBootstrapService,AdminNavigationService}`, `InventoryQueryService`, `Customers/CustomerLookupService`, إعدادات الأعمال والمطعم، Enums الطلبات والمدفوعات، أدوار وصلاحيات Seeder، Resources الفرع والمنتج والهوية، و`tests/Feature/{ReportsTest,InventoryManagementTest}.php`، و`docs/admin-api.md`.
2. **Dashboard:** `GET /api/v1/admin/dashboard`.
3. **الاستجابة:** `{success,message,data,errors}`؛ `data` يحتوي `period`, `summary`, `order_statuses`, `order_types`, `comparison`, `definitions`. `comparison` يحتوي الفترة السابقة و`current`, `previous`, `changes`.
4. **KPIs:** عدد كل الطلبات والمكتملة والملغاة والمعلقة والمؤكدة والتحضير والجاهزية والتوصيل؛ مبيعات الطلبات المكتملة ومتوسطها؛ نسبة الإكمال والإلغاء؛ العملاء الجدد؛ طلبات الضيوف والتوصيل والاستلام. `collected_revenue` مشروط بصلاحية تقارير الدفع، والمبيعات والمتوسط بصلاحية تقارير المبيعات. `inventory` مشروط بـ`inventory.view`. الواجهة لا تضع صفرًا لحقول محجوبة أو مفقودة.
5. **التاريخ:** `date_from`, `date_to` بصيغة `YYYY-MM-DD`، المدى 1–366 يومًا. Dashboard افتراضيًا اليوم؛ باقي التقارير آخر 30 يومًا حتى اليوم أو `date_to`. لا اختراع today/week presets أو تحويل حدود أيام في المتصفح.
6. **الفرع:** `branch_uuid` مع تحقق وجود الفرع. selector يأتي من قائمة الإدارة لمن لديه `branches.view` وإلا القائمة العامة. التقارير التاريخية تشمل الفروع غير النشطة والمحذوفة؛ يمكن فتحها بفلتر UUID صالح في URL رغم عدم ظهور الفرع المحذوف في قائمة الخيارات. لا توجد علاقة موظف بفرع في Backend؛ لا ادعاء وجود عزل فرعي غير منفذ.
7. **المنطقة الزمنية:** Backend يقرأ `restaurant.timezone`، والحالية `Asia/Riyadh`. بداية اليوم المحلي تتحول إلى UTC شاملة، ونهاية اليوم إلى بداية اليوم التالي غير شاملة. العميل لا يرسل `timezone` لأنها ممنوعة. عرض الفترة يستعمل calendar dates دون تغييرها بتوقيت الجهاز؛ أوقات قواعد البيانات غير المزودة بإزاحة تعامل كـUTC ثم تُعرض بتوقيت المطعم. العملة تؤخذ من `/admin/bootstrap`، والحالية SAR، دون تثبيتها في كود التقارير.
8. **المبيعات:** `GET /admin/reports/sales/trend` تحت قاعدة `/api/v1`.
9. **تعريف المبيعات:** مجموع `orders.total` للطلبات المكتملة وفق `placed_at` في الفترة. ليست مجموع عمليات الدفع أو الطلبات المنشأة غير المكتملة. متوسط قيمة الطلب يستخدم عدد المكتملة. التحصيل المالي مجموع sale/capture الناجحة حسب وقت `processed_at`. لا حساب صافي استرداد؛ تقرير المدفوعات يصرح بأن الاسترداد غير مدعوم.
10. **الطلبات:** `GET /admin/reports/orders`.
11. **مؤشرات الطلبات:** أعداد الحالات، عدد التوصيل والاستلام والضيوف، مبيعات المكتملة ومتوسطها ونسب الإكمال والإلغاء. جدول جميع الطلبات المطابقة مع إجماليات محفوظة.
12. **الحالات:** Dashboard يعيد خريطة كل الحالات. التقرير المستقل `GET /admin/reports/order-statuses` يعيد الحالات الموجودة مع العدد، وعينة ومتوسط مدة التحضير الصالحة.
13. **الفروع:** `GET /admin/reports/branches` يعرض الطلبات والمكتملة والملغاة ومبيعات المكتملة ومتوسطها والتوصيل والاستلام. ترتيب Backend بالمبيعات، مع زر فتح نفس التقرير للفرع.
14. **المنتجات:** `GET /admin/reports/products`: كمية وعدد الطلبات وقيمة العناصر قبل الخصم والخصم وبعده. الأسماء من الكتالوج الحالي غير المحذوف وإلا آخر snapshot مطابق؛ المبالغ تاريخية. `sort_by=quantity|net_sales|orders_count` على الخادم.
15. **التصنيفات:** `GET /admin/reports/categories`: snapshots وقت الطلب، وعناصر legacy null تظهر «غير محدد». تغيير/حذف التصنيف لا يعيد كتابة التاريخ، وقد تظهر مجموعات مستقلة لأسماء سابقة.
16. **العملاء:** `GET /admin/reports/customers`: العملاء المسجلون والجدد وأصحاب الطلبات والعائدون، وطلبات الضيوف. العائد لديه أكثر من طلب مطابق في الفترة. الجدول يظهر الاسم والطلبات والإنفاق المكتمل وآخر طلب؛ لا هاتف ولا بريد.
17. **المدفوعات:** `GET /admin/reports/payments`: عدد المدفوعات المنشأة، المدفوعات ذات التحصيل الناجح، محاولات فاشلة، المبلغ المحصل. تفصيل الطريقة والمزود والحالة وعدد العمليات. المدفوعات المعلقة بلا عمليات تظهر. فشل الدفع لا يعرض كإلغاء طلب. لا عرض `refunded_amount=null` كصفر.
18. **العروض والكوبونات:** `/admin/reports/promotions`, `/admin/reports/coupons`: استخدام الطلبات المكتملة والطلبات المتأثرة والخصم؛ العرض يتضمن مبيعات منسوبة قبل وبعد خصومات الطلب كله. النسب غير قابلة للجمع عند تراكب عروض؛ لا ROI ولا احتساب من الكوبونات الحالية.
19. **التوصيل:** `/admin/reports/delivery`: التوصيل والاستلام والتوصيل المجاني المكتمل ورسوم الطلبات المكتملة؛ المناطق وأسماؤها من snapshots، دون اختراع تقرير مناطق غير مخدومة أو أداء شركات توصيل.
20. **الإلغاء:** `/admin/reports/cancellations`: أسباب الإلغاء كما سجلت، عدد وقيمة الملغاة. لا تحليل نصوص الأسباب ولا تسمية قيمة الملغاة كخسارة محصلة.
21. **السلسلة الزمنية:** `{period, group_by, data:[{period,orders_count,completed_orders_count,completed_sales,average_order_value}]}`. الخادم يعيد الفترات الصفرية؛ الواجهة لا تعيد تجميعها.
22. **الدقة:** day/week/month فقط؛ الأسبوع يبدأ الاثنين. لا hourly endpoint.
23. **المقارنة:** Dashboard فقط، مع الفترة السابقة المساوية في عدد الأيام المحلية. `percentage_change=null` عندما السابق صفر والحالي غير صفر؛ يُعرض تفسير دون Infinity/NaN. نسب صفرية حقيقية تعرض 0%.
24. **الفلاتر:** التاريخ والفرع ونوع/حالة الطلب وطريقة الدفع؛ group_by للمبيعات، sort_by للمنتجات، page/per_page للتقارير المرقمة. توجد أيضًا `/admin/reports/inventory` كملخص حي مع branch_uuid/product_uuid/category_uuid/search/low_stock/out_of_stock. اختيار المنتج يدعم بحثًا على الخادم؛ قائمة التصنيفات تأتي من endpoint الكتالوج الفعلية. الفلاتر لا تنقل timezone أو مفاتيح مجهولة.
25. **الترتيب والترقيم:** `data.meta={current_page,per_page,total,last_page}` داخل success data. 20/50/100 صفًا. ترتيب Backend محفوظ، لا ترتيب محلي ولا بحث غير مدعوم للتقارير التجارية. sales/trend ليست مرقمة؛ المخزون ملخص. رسوم التقارير المرقمة توضح أنها تمثل الصفحة الحالية.
26. **CSV:** GET `/admin/reports/{orders|sales/trend|products|payments|customers|promotions}/export`. لا أزرار تصدير للتقارير التي لا تملك endpoint.
27. **الصيغة:** stream CSV UTF-8 مع BOM، رأس الحقول من Backend، مبالغ decimal، حماية formula injection على الخادم. التحميل Blob، والتحقق من Content-Type، واستخدام Content-Disposition إن أمكن قراءتها؛ fallback آمن `report.csv`. لا تصدير جدول محلي.
28. **فلاتر التصدير:** نفس الفلاتر المطبقة مع الفترة الفعلية التي عادت من Backend، وليس draft غير مطبق. كل النتائج المطابقة دون حدود صفحة الواجهة. تصدير الدفع يعرض ledger transactions لا جدول breakdown. حد متزامن 100,000 صف؛ لا queued export flow في هذا العقد. إلغاء تغيير التقرير/الفلاتر يمنع تنزيل قديم. JSON الأخطاء داخل Blob يفك قبل عرضه ولا يحفظ كـCSV.
29. **صلاحية التصدير:** صلاحية التقرير نفسه بالإضافة إلى `reports.export`، ومعدل 5 طلبات/دقيقة. الزر لا يظهر دون الصلاحية، ويتعطل أثناء الطلب؛ 429 و422 يظهران كأخطاء.
30. **صلاحيات الشاشات:** Dashboard `dashboard.view`. sales `reports.sales.view`؛ orders/statuses/cancellations `reports.orders.view`؛ branches `reports.branches.view`؛ products/categories `reports.products.view`؛ payments `reports.payments.view`؛ customers `reports.customers.view`؛ promotions/coupons `reports.promotions.view`؛ delivery `reports.delivery.view`؛ inventory `inventory.view`. التقارير وSidebar تظهر عند أي صلاحية مناسبة؛ كل tab والرابط المباشر يتحققان من صلاحيتهما. لا اعتماد على `reports.view` العامة لإتاحة تقرير محمي.

## التنفيذ — البنود 31–39

31. **ملفات Vue الجديدة:** `src/api/{dashboard.api,reports.api}.js`، `src/constants/reports.js`، `src/utils/reportFormatters.js`، `src/composables/useReportContext.js`، `src/components/reports/{ReportFilters,ReportMetrics,ReportChart,ReportTable}.vue`، `src/views/admin/ReportsView.vue`، `src/assets/css/reports.css`، `tests/reports.browser.mjs`.
32. **المعدلة:** `DashboardView.vue`، `router/{index,authGuards}.js`، `constants/adminNavigation.js`، `components/admin/AdminSidebar.vue`، `.gitignore` لتجاهل artifacts الاختبار. هذا التقرير نفسه أضيف بناء على طلب التقرير النهائي.
33. **المسار الجديد:** `/admin/reports?report=...`. الفلاتر محفوظة في URL للتحديث وروابط التقارير؛ Dashboard يحتفظ بحالة الفلاتر أثناء العرض ويطلب الافتراضي الحقيقي عند إعادة تحميل كاملة.
34. **Sidebar:** إضافة «التقارير» بحسب الصلاحيات granular، بما في ذلك مستخدم لديه صلاحية تقرير دون Dashboard؛ دعم اختيار الصفحة المسموح بها بعد الدخول ورفض intended URL غير المصرح.
35. **الرسوم:** أعمدة HTML/CSS تفاعلية بالـtooltips الأصلية مع قيم مكتوبة. لا Chart.js أو غيرها مثبتة في المشروع؛ لم تضف مكتبة. نفس cream/maroon/red والبطاقات وTajawal وRTL. لا instances قابلة للتسريب؛ هندسة طول العمود عرض فقط، ولا تستخدم لحساب KPI أو مجموع مبيعات.
36. **Missing UI:** Dashboard السابق احتوى آخر الطلبات ومعاينة، دون KPIs/رسوم/فلاتر. لا صفحة تقارير مستقلة أو CSV UI أو مقارنة فترات.
37. **المنفذ:** Dashboard مع المؤشرات والمقارنة ورسوم الحالات والنوع والمبيعات والمخزون، و13 tab للتقارير، وجداول وترقيم وترتيب المنتجات وفتح الفرع وتصدير الستة. آخر 5 طلبات تأتي مرة واحدة من `/admin/orders` بفلاتر متوافقة وترتيب placed_at desc، دون استخدامها لأي حساب. أخطاء endpoint فرعية لا تحذف المؤشرات الناجحة. Refresh يدوي، لا polling للتقارير. AbortController ورقم مراجعة يحميان من الردود المتأخرة.
38. **Backend-only:** معرفات الجداول الداخلية، SQL، metadata التنفيذ، التفاصيل الأمنية والرموز لا تظهر. معرفات UUID مخفية كقيم اختيار/روابط فقط. UTC bounds ليست نماذج إعدادات. definitions المفيدة للأعمال عُرضت كتفسير عربي. لا اختراع تقارير ساعة أو ROI أو refunds أو بحث تجاري غير مدعوم.
39. **Demo المحذوف:** قسم معاينة المنتجات والعروض من Dashboard. كل KPI/رسم/جدول تقارير يأتي من endpoints. بيانات Settings/CMS وأي معاينات وحدات أخرى لم تُعدّل. بيانات الاختبار المحددة موجودة في test script فقط، وليست fallback في production.

## التحقق — البنود 40–57

ملف الأدلة: `.reports-check/report.json`. ملفات CSV محفوظة في نفس المجلد. test script يفصل `live` عن `browser` scenarios المحددة. لا توكنات أو كلمات مرور في التقرير، وتوكنات جلسات الاختبار تُلغى عبر logout.

40. **Dashboard:** Laravel ReportsTest ناجح؛ HTTP الحقيقية عادت 28 طلبًا و4 مكتملة، مبيعات 236.00 ومتوسط 59.00؛ مقارنة قيمة بطاقة العدد باستجابة HTTP في المتصفح الحقيقي.
41. **التواريخ:** يوم 2026-10-04، أسبوع 2026-09-28 إلى 2026-10-04، شهر أكتوبر؛ جميعها API 200. الدقة day/week/month مغطاة باختبارات Backend.
42. **الفترة الفارغة:** 2020-01-01 API 200 مع orders=0 وsales=0.00؛ حالات العرض الفارغ تختبر بصورة منفصلة.
43. **الفرع:** API الحقيقية بفرع موجود نجحت. يوجد فرع واحد في البيانات المحلية؛ فرعان وتمرير filter وdrilldown يختبران بعقد محدد في المتصفح. UUID غير موجود أعاد 422. لا اختبار عزل موظف بفرع لأنه غير موجود في Backend.
44. **المبيعات:** HTTP trend الحقيقي ناجح؛ حدود اليوم والأسبوع والشهر والفترات الصفرية وaverage تستند إلى ReportsTest، دون جمع في Vue.
45. **الطلبات:** HTTP الحقيقي 28، أربع طلبات بكل حالة، 16 توصيل و12 استلام. جدول مرقم Backend؛ نسب الإكمال والإلغاء 14.29%.
46. **المنتجات:** HTTP الحقيقي 5 صفوف. ReportsTest يغطي الترتيبات الثلاثة وsnapshots وتغيير السعر والاسم والحذف. العرض والترتيب وترقيم الصفحات يختبران في المتصفح.
47. **العملاء:** HTTP الحقيقي 6 عملاء أصحاب طلبات و6 عائدين و0 طلب ضيف، و7 مسجلين وفق تعريف Backend؛ snapshot tests تستبعد الموظفين والإنفاق الملغى وتفصل الضيوف.
48. **المدفوعات:** HTTP الحقيقي 28 مدفوعًا منشأ و4 ذات تحصيل ناجح، 236.00 محصل، محاولات فاشلة 0؛ ReportsTest يغطي تواريخ المعالجة ومحاولات الدفع وعدم ازدواج sale/capture وفروع الطلبات القديمة.
49. **العروض/التوصيل/الإلغاء:** API الحقيقي: العروض والكوبونات فارغة لعدم وجود snapshots استخدام، وهذا Empty صحيح. التوصيل 16، الاستلام 12، رسوم المكتملة 10.00. الإلغاء 4 بقيمة 239.00 وأسباب null تعرض «غير محدد». Tests Backend تغطي استخدام عروض حقيقي وsnapshots وأسباب الإلغاء. لا تزعم قياس استخدام غير موجود.
50. **Timezone:** اختبارات Laravel الموجودة تغطي 20:59:59/21:00 UTC لليوم في الرياض وDST بمنطقة بديلة، والفترة السابقة وأقصى 366 يومًا. البيانات الحية أعادت اليوم من 2026-10-03T21:00Z حتى 2026-10-04T21:00Z غير شامل. لم تُنشأ طلبات قرب منتصف الليل في قاعدة المشروع.
51. **CSV:** الستة API 200 والملفات الحقيقية محفوظة؛ rate limit أعاد 429 عند الطلب السادس ثم جُرّب بعد انتهاء النافذة. متصفح fixtures يختبر Blob download واسم الخادم والحفاظ على الفلاتر ورفض JSON كملف.
52. **Export permissions:** كاشير حقيقي عاد 403 للتصدير و403 لتقرير الدفع، العميل 403 للDashboard والمجهول 401؛ Laravel يختبر الستة مع كل تركيب صلاحيات التقرير والتصدير.
53. **Rapid changes:** آلية useApiState تستخدم abort/revision مع حماية متابعة Dashboard. نجح اختبار المتصفح الذي يحتجز استجابة ويغير الفلتر ثم يتحقق من عدم كتابة العدد 999 فوق الاستجابة الجديدة.
54. **Backend issues/limits:** لا staff-to-branch scope. استجابة التقارير المالية لا تضم العملة، لذلك bootstrap ضروري. إعداد Laravel الافتراضي يحتوي `exposed_headers=[]`، وتأكد في تنزيل المتصفح الحقيقي أن Content-Disposition غير مقروء مع frontend/API على origin مختلفين؛ الملف نزل بنجاح بالاسم الاحتياطي `report.csv`. مناطق/تصنيفات بعض بيانات Seeder السابقة غير محفوظة كسنابشوت فتظهر null. لا تغيير Backend لمعالجة هذه الحدود في هذه المهمة.
55. **Build:** `npm run build` ناجح. يوجد تحذير سابق `INEFFECTIVE_DYNAMIC_IMPORT` في apiError.js بسبب static/dynamic imports في كتالوج ووحدات قائمة؛ لا خطأ بناء.
56. **Console:** نجح browser script؛ `diagnostics=[]`, `assetFailures=[]`، دون failure. النتيجة النهائية في `.reports-check/report.json`، ولا اعتبار طلب API مرفوض متعمد كفشل أصل واجهة.
57. **المتبقي:** محدودية بيانات فرع واحد وغياب استخدام العروض التاريخي في قاعدة التطوير؛ لا تحويلها إلى بيانات وهمية. لا endpoints hourly/refund analytics/queued export، ولا عزل فرعي للموظفين. لا يلزم تعديل Backend لتنفيذ تقارير العقود الحالية.

## BACKEND → FRONTEND COVERAGE

| Backend feature | Existing UI | Implemented UI |
| --- | --- | --- |
| Dashboard + comparison + status/type breakdown | آخر طلبات ومعاينة فقط | بطاقات المؤشرات والمقارنة والرسوم والتاريخ والفرع والحالات |
| Sales trend day/week/month | غير موجودة | رسم وجدول ودقة وفلاتر وتصدير |
| Orders/statuses/cancellations | صفحة عمليات الطلبات فقط | تقارير مستقلة ونسب وأسباب ووقت التحضير وتصدير الطلبات |
| Branch/product/category performance | CRUD فقط | تقارير وجداول مرقمة وترتيب منتج وفتح الفرع |
| Payment ledger analytics | عرض عمليات الدفع | تجميع الطريقة/الحالة والمزود والتحصيل وتصدير ledger |
| Promotion/coupon analytics | إدارة العروض | تقارير تاريخية مع توضيح non-additive attribution |
| Customer/delivery analytics | وحدات خارج هذا النطاق | إحصاءات وجداول تعريفها من Backend |
| Inventory summary endpoint | لا تقرير | لقطة حالية وفلاتر الكتالوج والمخزون |
| Permission/validation/CSV transport | أساس Auth/API موجود | granular guards، field errors، retry، Blob/content type، إلغاء الردود القديمة |

الأشياء الداخلية التي لم تعرض: تفاصيل SQL والحسابات الوسيطة والأسرار، لأنها لا تمثل إجراء عمل للمستخدم. وظائف غير موجودة لم تُختلق. لا تغيير لوحدات Settings/CMS.

## NEXT MODULE READINESS

العقود الحالية للتقارير أصبحت لها واجهة فعلية. يمكن الانتقال لاحقًا إلى Settings + Restaurant Operations + Business Hours + Schedule Exceptions + Branch Operational Status + Ordering Maintenance + Public Settings + Branch Open/Closed State + Ordering Availability، بعد audit خاص بها. bootstrap مستخدم هنا لعملة/منطقة التقارير فقط، وليس بداية لربط هذه الوحدات. لم تبدأ هذه المرحلة التالية.
