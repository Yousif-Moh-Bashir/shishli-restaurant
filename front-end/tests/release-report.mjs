import assert from 'node:assert/strict'
import { readFile, writeFile, readdir } from 'node:fs/promises'
import { gzipSync } from 'node:zlib'

const directory = '../docs/release/'
const logoBytes = (await readFile('src/assets/images/logo/shishli-logo.webp')).length
const backgroundBytes = (await readFile('src/assets/images/banners/restaurant-reference.webp')).length
const read = async name => JSON.parse(await readFile(directory + 'evidence/' + name + '.json', 'utf8'))
const modes = {}
for (const mode of ['production', 'development']) {
  modes[mode] = {}
  for (const stage of ['before', 'after']) {
    const report = await read(stage + '-' + mode)
    assert.equal(report.pages.length, 8)
    assert.equal(report.repetitions, 1)
    assert.deepEqual(report.diagnostics, [])
    assert.ok(report.pages.every(row => row.failures.length === 0 && !row.overflow))
    modes[mode][stage] = report
  }
}
const fmt = (value, precision = 0) => Number.isFinite(value) ? value.toFixed(precision) : 'N/A'
const pair = (before, after, field, precision = 0, divisor = 1) => fmt(before[field] / divisor, precision) + ' → ' + fmt(after[field] / divisor, precision)
let doc = `# Performance before / after — Phase 1

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

`
for (const mode of ['production', 'development']) {
  const { before, after } = modes[mode]
  doc += `## ${mode === 'production' ? 'Production build' : 'Development'}

الأرقام زمنها ms؛ السهم قبل → بعد، وKiB = 1024 bytes.

| الصفحة | FCP ms | LCP ms | CLS | TTFB ms | JS KiB | Images KiB | API requests |
|---|---|---|---|---|---|---|---|
`
  for (const a of before.pages) {
    const b = after.pages.find(row => row.name === a.name)
    assert.ok(b)
    doc += `| ${a.name} | ${pair(a,b,'fcp')} | ${pair(a,b,'lcp')} | ${pair(a,b,'cls',4)} | ${pair(a,b,'ttfb',1)} | ${pair(a,b,'js_bytes',1,1024)} | ${pair(a,b,'image_bytes',1,1024)} | ${pair(a,b,'api_count')} |
`
  }
  doc += `
| الصفحة | CSS KiB قبل → بعد | Fonts KiB قبل → بعد | local interaction proxy ms قبل → بعد |
|---|---|---|---|
`
  for (const a of before.pages) {
    const b = after.pages.find(row => row.name === a.name)
    const fonts = row => row.assets.filter(asset => asset.type === 'Font').reduce((sum, asset) => sum + asset.bytes, 0)
    doc += `| ${a.name} | ${pair(a,b,'css_bytes',1,1024)} | ${fmt(fonts(a)/1024,1)} → ${fmt(fonts(b)/1024,1)} | ${fmt(a.interaction_ms,1)} → ${fmt(b.interaction_ms,1)} |
`
  }
  doc += `
الدليل: [قبل](evidence/before-${mode}.json)، [بعد](evidence/after-${mode}.json). screenshots لكل صفحة في evidence/. لا JavaScript diagnostics أو failed resources أو overflow في هذه العينات.

`
}
const beforeApi = await read('before-api'), afterApi = await read('after-api')
const median = values => [...values].sort((a,b) => a-b)[Math.floor(values.length/2)]
const timing = (sample, field) => Number(sample.serverTiming.match(new RegExp(field === 'queries' ? 'queries;desc="(\\d+)"' : '\\b' + field + ';dur=([\\d.]+)'))?.[1])
doc += `## API — medians لسبع عينات، MariaDB

| Endpoint | HTTP ms قبل → بعد | app ms قبل → بعد | boot ms قبل → بعد | PDO/query ms قبل → بعد | query count قبل → بعد | payload bytes قبل → بعد |
|---|---|---|---|---|---|---|
`
const apiSummary = []
for (const a of beforeApi.endpoints) {
  const b = afterApi.endpoints.find(row => row.path === a.path)
  const stat = (row, field) => median(row.samples.map(sample => field === 'ms' || field === 'bytes' ? sample[field] : timing(sample,field)))
  apiSummary.push({ path: a.path, before_ms: stat(a,'ms'), after_ms: stat(b,'ms'), before_queries: stat(a,'queries'), after_queries: stat(b,'queries') })
  doc += `| ${a.path} | ${fmt(stat(a,'ms'),2)} → ${fmt(stat(b,'ms'),2)} | ${fmt(stat(a,'app'),2)} → ${fmt(stat(b,'app'),2)} | ${fmt(stat(a,'boot'),2)} → ${fmt(stat(b,'boot'),2)} | ${fmt(stat(a,'db'),2)} → ${fmt(stat(b,'db'),2)} | ${fmt(stat(a,'queries'))} → ${fmt(stat(b,'queries'))} | ${fmt(stat(a,'bytes'))} → ${fmt(stat(b,'bytes'))} |
`
}
doc += `
[before API](evidence/before-api.json) و[after API](evidence/after-api.json): 140 استجابة HTTP حقيقية. app/boot/db من router اختبار منفصل وليس middleware منشورًا. DB::listen يشمل PDO/fetch overhead؛ لا يمثل زمن MySQL server وحده. query count لا يشمل عمل bootstrap السابق لتسجيل listener. الفرق بين HTTP وapp يضم نقل localhost/الخادم.

## نتائج مراجعة أسباب البطء

- أكبر فرق مؤكد في هذا التدقيق هو استخدام Development مقابل بناء Production تحت throttling؛ قارن الزمن وحجم JS بين الجدولين. لا تستخدم Development للإنتاج.
- إصلاح QA-006 قديمًا ظاهر في البناء الحالي: الشعار ${logoBytes.toLocaleString('en-US')} bytes والخلفية ${backgroundBytes.toLocaleString('en-US')} bytes مقابل الملفات القديمة 783,022 و1,644,970 bytes. هذا مكسب سابق، وليس تعديلًا جديدًا هنا. استخدمت القياسات الحالية الصور الموجودة قبل وبعد بلا إعادة ضغط.
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
`
const assets = []
for (const name of await readdir('dist/assets')) {
  if (!/\.(js|css|webp|png|jpg)$/.test(name)) continue
  const bytes = await readFile('dist/assets/' + name)
  assets.push({ name, bytes: bytes.length, gzip_bytes: /\.(js|css)$/.test(name) ? gzipSync(bytes).length : null })
}
await writeFile(directory + 'evidence/build-assets.json', JSON.stringify({ scope: 'Final production build / relative API base', assets }, null, 2))
doc += `
جرد ملفات البناء وgzip: [build-assets.json](evidence/build-assets.json). مجموع جميع JS chunks على القرص ${fmt(assets.filter(a=>a.name.endsWith('.js')).reduce((s,a)=>s+a.bytes,0)/1024,1)} KiB، وليس حجم تحميل صفحة واحدة. مصدر القياس والتفسير: [Chrome Performance](https://developer.chrome.com/docs/devtools/performance/reference/) و[تعريف INP](https://web.dev/articles/inp).
`
await writeFile(directory + 'PERFORMANCE_BEFORE_AFTER.md', doc)
await writeFile(directory + 'evidence/performance-summary.json', JSON.stringify({ browser_samples: 32, api_samples: 140, api: apiSummary, production_home: { before: modes.production.before.pages[0], after: modes.production.after.pages[0] } }, null, 2))
console.log(JSON.stringify({ result: 'passed', browser_samples: 32, api_samples: 140, reports_generated: ['PERFORMANCE_BEFORE_AFTER.md', 'build-assets.json', 'performance-summary.json'] }))
