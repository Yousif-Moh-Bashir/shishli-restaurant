# Performance before / after — Phase 1

2026-10-11. هذه قياسات **جديدة محلية**؛ الأدلة التاريخية في docs/performance لم تُستبدل.

## المنهج وحدود الاستنتاج

- الصفحات الثماني: Home/Menu/Product/Cart/Checkout/Admin Dashboard/Admin Orders/Driver Orders، قبل وبعد، Development وProduction منفصلان.
- Chrome headless، 390×844 DPR1، CPU slowdown 4×، 1.6Mbps down / 750Kbps up / latency 100ms، cache المتصفح معطل. fixture MariaDB معزول واحد؛ السلة لها المنتج نفسه وكمية 3، والأدوار نفسها. لا الدفع والإرسال الحقيقيان.
- **عينة متصفح واحدة لكل صفحة/مرحلة/نسخة**؛ ليست medians لثلاث عينات ولا نتيجة Lighthouse. لا تدعي دلالة إحصائية أو سرعة شبكة/جهاز الإنتاج. تؤثر caches المحلية وWindows/background activity في الزمن؛ بعض التحقق القصير تزامن مع جزء من القياس البعدي.
- Production المقاسة بناء Vite مصغر في dist-performance-release مرتبط بـAPI localhost 8019. البناء النهائي dist يستعمل /api/v1؛ reverse proxy الإنتاج لم يُختبر.
- القياس يسجل FCP/LCP/CLS وTTFB لوثيقة Vite، bytes المنقولة وAPI requests. بيانات كل مورد وطلب وServer-Timing في JSON. TTFB الوثيقة لا يساوي زمن Laravel؛ الجدول المنفصل للـ API يحتوي 7 طلبات متسلسلة لكل endpoint قبل وبعد، دون throttling.
- فحص admin/me لهوية عميل قد يعيد 403 متوقعًا لاكتشاف الدور؛ لا يُعد نجاح عملية إدارية ولا خلل checkout. failed resources هنا أخطاء نقل الموارد، لا كل استجابة 4xx مقصودة.
- تم تنفيذ pointer focus حين توجد input ظاهرة. هذا **local interaction proxy** فقط؛ لا field INP، ولا تُدّعى قياسات INP حقيقية من عينة قصيرة.
- التغييرات الحالية إعدادات CORS/proxy واختبار MySQL؛ لم يتغير Vue/CSS/SQL/cache/images. لذلك فروق الزمن أدناه **ليست تحسنًا منسوبًا إلى إصلاح أداء في هذه الجلسة**. لا Redis مضاف.

## Production build

الأرقام زمنها ms؛ السهم قبل → بعد، وKiB = 1024 bytes.

| الصفحة | FCP ms | LCP ms | CLS | TTFB ms | JS KiB | Images KiB | API requests |
|---|---|---|---|---|---|---|---|
| Home | 3592 → 3480 | 4668 → 4644 | 0.0002 → 0.0002 | 21.3 → 4.6 | 115.6 → 115.6 | 273.1 → 273.1 | 7 → 7 |
| Menu | 1832 → 1780 | 1832 → 1780 | 0.0177 → 0.0253 | 2.9 → 2.8 | 107.4 → 107.4 | 38.7 → 38.7 | 8 → 8 |
| Product | 1368 → 1500 | 3868 → 4680 | 0.0869 → 0.0514 | 3.0 → 3.9 | 106.5 → 106.5 | 38.7 → 38.7 | 7 → 7 |
| Cart | 3488 → 2900 | 3696 → 3060 | 0.0038 → 0.0038 | 3.1 → 2.9 | 97.9 → 97.9 | 38.7 → 38.7 | 6 → 6 |
| Checkout | 3408 → 2800 | 5800 → 4624 | 0.0724 → 0.0109 | 2.8 → 4.3 | 119.0 → 119.0 | 38.7 → 38.7 | 6 → 6 |
| Admin Dashboard | 3544 → 3916 | 3544 → 3916 | 0.0676 → 0.0675 | 2.6 → 3.3 | 124.6 → 124.6 | 38.7 → 38.7 | 8 → 8 |
| Admin Orders | 3704 → 3236 | 8276 → 6004 | 0.0304 → 0.0304 | 5.5 → 4.2 | 125.3 → 125.3 | 38.7 → 38.7 | 7 → 7 |
| Driver Orders | 4112 → 3164 | 4112 → 3164 | 0.0042 → 0.0049 | 2.9 → 3.9 | 103.4 → 103.4 | 0.0 → 0.0 | 5 → 5 |

| الصفحة | CSS KiB قبل → بعد | Fonts KiB قبل → بعد | local interaction proxy ms قبل → بعد |
|---|---|---|---|
| Home | 18.1 → 18.1 | 85.5 → 85.5 | N/A → N/A |
| Menu | 18.1 → 18.1 | 66.8 → 66.8 | 56.0 → 32.0 |
| Product | 17.8 → 17.8 | 37.6 → 37.6 | N/A → N/A |
| Cart | 17.8 → 17.8 | 37.6 → 37.5 | N/A → N/A |
| Checkout | 21.4 → 21.4 | 57.2 → 57.1 | N/A → N/A |
| Admin Dashboard | 30.7 → 30.7 | 57.1 → 57.1 | 448.0 → 528.0 |
| Admin Orders | 30.4 → 30.4 | 57.1 → 57.1 | 232.0 → 216.0 |
| Driver Orders | 19.9 → 19.9 | 57.1 → 57.1 | N/A → N/A |

الدليل: [قبل](evidence/before-production.json)، [بعد](evidence/after-production.json). screenshots لكل صفحة في evidence/. لا JavaScript diagnostics أو failed resources أو overflow في هذه العينات.

## Development

الأرقام زمنها ms؛ السهم قبل → بعد، وKiB = 1024 bytes.

| الصفحة | FCP ms | LCP ms | CLS | TTFB ms | JS KiB | Images KiB | API requests |
|---|---|---|---|---|---|---|---|
| Home | 22676 → 22532 | 24232 → 24140 | 0.0003 → 0.0003 | 114.7 → 73.0 | 3979.9 → 3979.9 | 273.1 → 273.1 | 7 → 7 |
| Menu | 21668 → 21636 | 21668 → 21636 | 0.0171 → 0.0190 | 308.1 → 149.2 | 3854.7 → 3854.7 | 38.7 → 38.7 | 8 → 8 |
| Product | 21576 → 21568 | 24344 → 23912 | 0.1381 → 0.1177 | 96.0 → 134.2 | 3860.7 → 3860.7 | 38.7 → 38.7 | 7 → 7 |
| Cart | 22144 → 21596 | 22412 → 21824 | 0.0112 → 0.0099 | 420.5 → 115.2 | 3747.2 → 3747.2 | 38.7 → 38.7 | 6 → 6 |
| Checkout | 24404 → 24340 | 26076 → 26156 | 0.0281 → 0.0332 | 115.4 → 126.7 | 4210.7 → 4210.7 | 38.7 → 38.7 | 6 → 6 |
| Admin Dashboard | 24492 → 24584 | 24492 → 24584 | 0.0675 → 0.0675 | 118.4 → 187.5 | 4227.9 → 4227.9 | 38.7 → 38.7 | 9 → 9 |
| Admin Orders | 24080 → 24520 | 26912 → 27304 | 0.0304 → 0.0304 | 104.3 → 241.3 | 4171.6 → 4171.6 | 38.7 → 38.7 | 7 → 7 |
| Driver Orders | 22948 → 22588 | 22948 → 22588 | 0.0049 → 0.0049 | 170.6 → 146.3 | 3827.8 → 3827.8 | 0.0 → 0.0 | 5 → 5 |

| الصفحة | CSS KiB قبل → بعد | Fonts KiB قبل → بعد | local interaction proxy ms قبل → بعد |
|---|---|---|---|
| Home | 1.1 → 1.1 | 85.5 → 85.4 | N/A → N/A |
| Menu | 1.1 → 0.7 | 66.8 → 66.1 | 32.0 → 24.0 |
| Product | 0.7 → 1.1 | 38.2 → 38.1 | N/A → N/A |
| Cart | 1.1 → 0.7 | 37.6 → 37.6 | N/A → N/A |
| Checkout | 0.7 → 0.7 | 57.1 → 57.3 | N/A → N/A |
| Admin Dashboard | 0.7 → 0.7 | 57.1 → 57.3 | 288.0 → 312.0 |
| Admin Orders | 0.7 → 0.7 | 57.3 → 57.3 | 368.0 → 360.0 |
| Driver Orders | 0.7 → 0.7 | 57.3 → 57.3 | N/A → N/A |

الدليل: [قبل](evidence/before-development.json)، [بعد](evidence/after-development.json). screenshots لكل صفحة في evidence/. لا JavaScript diagnostics أو failed resources أو overflow في هذه العينات.

## API — medians لسبع عينات، MariaDB

| Endpoint | HTTP ms قبل → بعد | app ms قبل → بعد | boot ms قبل → بعد | PDO/query ms قبل → بعد | query count قبل → بعد | payload bytes قبل → بعد |
|---|---|---|---|---|---|---|
| /home?branch=:branch | 232.45 → 233.76 | 226.23 → 227.53 | 122.78 → 119.90 | 15.84 → 24.88 | 17 → 17 | 9146 → 9146 |
| /menu?branch=:branch | 222.95 → 215.74 | 217.35 → 210.50 | 116.90 → 118.64 | 21.16 → 18.09 | 9 → 9 | 6299 → 6299 |
| /menu/products/:product?branch=:branch | 200.72 → 214.67 | 195.61 → 209.18 | 115.68 → 117.32 | 7.51 → 20.38 | 9 → 9 | 1067 → 1067 |
| /branches | 183.96 → 164.48 | 178.70 → 159.62 | 119.26 → 115.22 | 14.61 → 2.31 | 1 → 1 | 729 → 729 |
| /cart | 232.15 → 239.64 | 225.75 → 233.83 | 117.81 → 120.18 | 24.98 → 35.19 | 16 → 16 | 1127 → 1127 |
| /addresses | 187.55 → 192.71 | 181.58 → 187.14 | 116.41 → 120.24 | 15.55 → 4.09 | 3 → 3 | 578 → 578 |
| /orders/:order/tracking | 203.86 → 204.62 | 198.06 → 199.28 | 118.62 → 121.40 | 20.84 → 28.44 | 8 → 8 | 2406 → 2406 |
| /admin/dashboard | 241.87 → 252.62 | 236.61 → 246.56 | 119.65 → 118.78 | 18.36 → 26.26 | 14 → 14 | 3228 → 3228 |
| /admin/orders | 284.50 → 270.41 | 279.64 → 265.09 | 121.72 → 117.34 | 24.76 → 23.41 | 13 → 13 | 8876 → 8876 |
| /driver/orders | 252.38 → 251.25 | 247.43 → 246.28 | 116.67 → 118.70 | 22.50 → 27.29 | 14 → 14 | 1469 → 1469 |

[before API](evidence/before-api.json) و[after API](evidence/after-api.json): 140 استجابة HTTP حقيقية. app/boot/db من router اختبار منفصل وليس middleware منشورًا. DB::listen يشمل PDO/fetch overhead؛ لا يمثل زمن MySQL server وحده. query count لا يشمل عمل bootstrap السابق لتسجيل listener. الفرق بين HTTP وapp يضم نقل localhost/الخادم.

## نتائج مراجعة أسباب البطء

- أكبر فرق مؤكد في هذا التدقيق هو استخدام Development مقابل بناء Production تحت throttling؛ قارن الزمن وحجم JS بين الجدولين. لا تستخدم Development للإنتاج.
- إصلاح QA-006 قديمًا ظاهر في البناء الحالي: الشعار 39,350 bytes والخلفية 239,762 bytes مقابل الملفات القديمة 783,022 و1,644,970 bytes. هذا مكسب سابق، وليس تعديلًا جديدًا هنا. استخدمت القياسات الحالية الصور الموجودة قبل وبعد بلا إعادة ضغط.
- MenuService يحمّل category/primary image دفعة واحدة؛ options للتفاصيل. AdminOrderQuery يفلتر يوم العمل، والنتائج paginated/eager loaded. MenuTest وOrderTrackingPerformanceTest الحالية نجحت؛ لا N+1 مؤكد جديد يستدعي تعديل SQL.
- [EXPLAIN/FK/index evidence](evidence/database.json) يتضمن orders_branch_id_placed_at_index وorders_driver_queue_index والفهارس الحالية. خمس منتجات fixture لا تكفي لإثبات أداء كبير أو تبرير فهرس جديد؛ افحص بيانات staging بحجم واقعي.
- branch/settings requests تُشارك عبر Pinia، والبحث debounced/cancellable. Home يستعمل CMS products/gallery الحالية. عد API requests تشمل auth/bootstrap/polling المتوقع، ولا تعني أن كل تكرار duplicate غير لازم.
- useOrderPolling يسلسل الطلبات بفاصل 15s افتراضيًا، ويتوقف عند tab مخفي ويطبق backoff/429؛ اختبار المنطق نجح. اختلاف عدد طلبات dashboard عند طول تحميل Development يمكن أن يشمل دورة polling إضافية.
- router يستعمل dynamic imports للصفحات الإدارية؛ Leaflet chunk مستقل. لم تُغير code splitting أو reactivity دون سبب مثبت.
- Tajawal يستخدم preconnect/display=swap؛ resource bytes للخط في الجدول والـJSON. الاتصال Google خارجي؛ لم تُبدل الخط أو تؤثر الهوية البصرية.
- لا إخفاء لعناصر أو redesign لتحسين LCP. قيم CLS التي تتجاوز 0.1 في عينات Product development تحتاج متابعة؛ لا تُخفى في التقرير. يوجد تفاوت في أزمنة الصفحات؛ لا تُعامل عينة واحدة كتحسن/تراجع ثابت.

## الخطوات الباقية

PHP-FPM/OPcache، gzip/Brotli وimmutable hashed-asset caching، قاعدة وإصدار MySQL المعتمد وfixture واقعي، ثم 3+ عينات Lighthouse/staging وfield Web Vitals/INP على الأجهزة الفعلية. لا توجد نتيجة load testing أو SLA أو Internet TTFB في هذا التدقيق.

## إعادة التنفيذ

استعمل setup/router الحاليين في back-end/tests/Support على قواعد shishli_perf_* فقط. لا cached config عادي ولا .env إنتاج. ابنِ وضع releaseaudit مع API localhost 8019 وpreview 4179، وdev 5179. performance-audit.mjs يدعم PERF_STAGE وPERF_MODE وPERF_REPETITIONS وPERF_PAGES وPERF_OUTPUT_DIRECTORY؛ القيم الحالية 1 وثماني صفحات وdocs/release/evidence. release-api.mjs يستعمل RELEASE_STAGE=before/after و7 عينات. شغّل الأحمال متسلسلة وأغلق البرامج الخلفية للحصول على مقارنة أدق. لا تشحن manifests أو router القياس ضمن تطبيق الإنتاج.

جرد ملفات البناء وgzip: [build-assets.json](evidence/build-assets.json). مجموع جميع JS chunks على القرص 971.8 KiB، وليس حجم تحميل صفحة واحدة. مصدر القياس والتفسير: [Chrome Performance](https://developer.chrome.com/docs/devtools/performance/reference/) و[تعريف INP](https://web.dev/articles/inp).
