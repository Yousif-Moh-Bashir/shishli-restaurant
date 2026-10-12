import { readFile, writeFile } from 'node:fs/promises'
const root = '../docs/performance/'
const read = async name => JSON.parse(await readFile(root + name + '.json', 'utf8'))
const median = values => { const sorted = values.filter(Number.isFinite).sort((a,b) => a-b); return sorted.length ? sorted[Math.floor(sorted.length/2)] : null }
const names = ['Home','Menu','Product','Cart','Checkout','Login','Addresses','Tracking','Admin Dashboard','Admin Orders','Driver Orders']
const value = (rows,name,key) => key==='interaction_ms'&&name==='Home' ? null : median(rows.filter(row=>row.name===name).map(row=>row[key]))
const fmt = (n,key) => n === null ? 'N/A' : key === 'cls' ? n.toFixed(4) : key.endsWith('_bytes') ? (n/1024).toFixed(1) : n.toFixed(key==='api_count'?0:1)
const percent = (a,b) => a === null || b === null || a === 0 ? 'N/A' : ((a-b)/a*100).toFixed(1)+'%'
const cell = (before,after,name,key) => { const a=value(before,name,key),b=value(after,name,key); return `${fmt(a,key)} → ${fmt(b,key)} (${percent(a,b)})` }
const timing = (sample,key) => Number(sample.timing?.match(new RegExp(key === 'queries' ? 'queries;desc="(\\d+)"' : '\\b'+key+';dur=([\\d.]+)'))?.[1] ?? NaN)
const plans = await read('mysql-plans'), images = await read('image-optimization'), inventory = await read('asset-inventory')
const productImages = await read('product-image-optimization'), imageTransfers = await read('product-image-transfers')
const beforeApi = await read('before-api'), afterApi = await read('after-api'), journey = await read('journey'), verification = await read('verification')
let doc = `# SHISHLI — Performance audit and optimization

Audit date: 2026-10-10. This report describes measured local behavior, not production field performance.

## Environment and safety

- Windows/XAMPP, PHP 8.3.29, Laravel 13.33.0; Node 20.19.0, Vite 8.3.2, Vue 3.5.42; Chrome 154.0.8037.98.
- The available MySQL-protocol server is **${plans.server_version}**. This is MariaDB, not Oracle MySQL. PDO MySQL was measured separately from SQLite. Docker CLI is installed, but both sandboxed and unrestricted checks found its engine unavailable, so an Oracle MySQL container could not be run in the current environment; Oracle MySQL production behavior still requires staging verification.
- Two newly seeded isolated databases were used. Initial fixture: ${plans.fixture_counts.products} products, ${plans.fixture_counts.orders} orders, ${plans.fixture_counts.users} users. No live customer database was queried or modified. Public upload files were inspected as files only.
- Synthetic CMS pages were published to exercise Home. Customer Cart/Checkout measurements used three units of a real seeded product. The seeded products have no uploaded product photographs: their image placeholders are genuine fixture behavior, not evidence about live product-image transfer.
- Home, Menu and Product were measured again after the final CMS failure fallback change, using a fresh identical synthetic fixture and the same browser/network/CPU protocol. The other eight after rows retain the completed original run; after-core files archive the previous three-page results. No API/query measurements were replaced by this frontend-only follow-up.
- Audit-only Laravel routers on 8019 (PDO MySQL) and 8020 (SQLite) point exclusively at the test fixtures. Vite development uses 5179; production preview uses 4179. Existing application ports were left alone.
- Laravel runs under the **single-worker PHP built-in server**, not PHP-FPM. Concurrent browser API requests queue locally. This limits conclusions about production API throughput. No broad cache, schema migration, endpoint change, pricing change or payment change was made.
- Both frontend modes use the same isolated Laravel testing environment: APP_DEBUG=false, CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync, MAIL_MAILER=array, BCRYPT_ROUNDS=4. The production label refers to the Vite build, not a production PHP-FPM/Redis deployment. Setup/password cost and real external services are outside the page-load benchmark.

## Measurement protocol

Chrome DevTools Protocol was used for mobile viewport 390×844, DPR 1, CPU slowdown ×4, 1.6 Mbps download, 750 Kbps upload and 100 ms simulated network latency. Each of the eleven pages received three cold browser-cache navigations in each mode, before and after. Medians are shown. Development and production have separate tables: development serves unbundled modules and must not be interpreted as production performance.

LCP, FCP, navigation TTFB and CLS were observed through browser Performance APIs; CLS uses the maximum 5-second session window with a 1-second gap, excluding recent input. The harness waits for initial resources, then attempts a form-input pointer interaction. Home's hidden search input has a zero-size box, so its body-pointer samples are excluded from the interaction table; raw events remain available. The maximum qualifying Event Timing duration elsewhere is a **local interaction proxy**, not field INP; N/A means no qualifying interaction was observed, not zero latency. Interaction medians include only recorded qualifying values, so no INP improvement is claimed from sparse samples. A later full order journey validates actual actions. Lighthouse CLI/dependencies were unavailable, so no Lighthouse scores are invented. Chrome Performance traces are included.

JS/CSS/image sizes are **actual encoded HTTP transfer bytes**, including protocol overhead, expressed in KiB, not the sum of all emitted build chunks. API counts exclude preflight OPTIONS; individual API status, duration, Server-Timing and resource lists are in the JSON evidence. TTFB in page tables describes the frontend HTML response; Laravel endpoint timings are reported separately. Long tasks are retained in the raw browser results.

Positive percentages mean reduction; negative percentages mean increase. Three repetitions describe this local experiment and do not establish statistical significance. Small timing/CLS differences can be noise. Browser timers may add a poll/refresh depending on the observation window.
Hard navigations create disposable guest carts; the schema therefore accumulates synthetic cart/token rows across runs. Order/product fixtures and the controlled cart payload remain the same during timing. The full journey and one photograph association are performed only after all page/API timings, so their mutations do not contaminate the comparisons.

## Confirmed problems and fixes

| Priority | Root cause / evidence | Implemented fix |
| --- | --- | --- |
| Home and shared images | 783,022-byte logo displayed at roughly 137×77 CSS px; 1,644,974-byte shared restaurant sprite dominated Home transfer and LCP | Logo resized to 480×320 WebP quality 90, retaining aspect ratio and transparency. Restaurant image re-encoded at quality 84 without changing its 1536×1024 sprite coordinates. Original source PNGs and the Şişli reference directory remain untouched. |
| Product images | Three existing PNG photographs total 8,415,933 bytes; their isolated static-file transfers take 14–16 seconds on the simulated mobile network | MediaService creates smaller WebP sidecars, caps the longest edge at 1600 px, preserves alpha/originals and database paths; existing ProductImageResource selects the ready local variant using the same JSON fields. New eligible uploads are optimized too; deletion removes originals and sidecars. |
| Home API workload | Home returned featured_products and gallery_preview, while the frontend also fetched menu, categories and gallery. Home has no category filter. | Render those existing Home response fields; request menu only for a nonempty visible search or CMS failure; skip categories on Home. A failed/unpublished CMS receives independent menu/gallery fallback so dishes remain available. Menu retains its existing behavior. Existing cancellation/revision guards and branch reloads remain active; explicit loads cancel pending debounce timers. |
| Checkout | Checkout forced a second public-settings request after the shared layout's pending request | Initial load now uses the shared deduplicated Pinia request. Explicit retry and force refresh remain available. Server pricing/checkout validation is unchanged. |
| Product stability | Chrome trace identifies footer and SELECT movement: no product loading area while branches were loading; a temporary inline skeleton changed selector height by 59 px | Show product loading content while branch data loads; retain the fixed-size disabled branch selector instead of inserting an extra layout-changing skeleton. Completed layout remains unchanged. Actual product photo receives high fetch priority and async decoding. |

No backend SQL optimization was justified by these fixtures. Existing eager loading, pagination and indexes were preserved. Removing duplicate browser calls reduces backend work; media URL values may point to optimized files but JSON contracts remain unchanged.

## Image results

| Asset | Before bytes | After bytes | Reduction | Output |
| --- | ---: | ---: | ---: | --- |
${images.map(row=>`| ${row.asset} | ${row.before_bytes} | ${row.after_bytes} | ${percent(row.before_bytes,row.after_bytes)} | ${row.width}×${row.height}, WebP q${row.quality} |`).join('\n')}

[Full image/font/icon inventory](asset-inventory.json) inspected ${inventory.files.length} assets and found ${inventory.identical_files.length} byte-identical groups. Source PNG and WebP versions are source/served variants, not duplicate transfers. Product-card images already use lazy loading and reserved aspect-ratio frames. Home/LCP assets are not lazy loaded. Responsive srcset variants remain a later improvement; the existing large product images now use smaller full-size WebP variants.

### Existing product images — separate static-file comparison

The original files were retained unchanged, allowing an original-versus-optimized comparison after conversion without using a customer database. Chrome loaded each file once through a static-only PHP server under the same mobile network/CPU settings. This is an isolated transfer experiment, **not Product page LCP**; it is not mixed into the placeholder-product page tables.

| Photograph | Original file bytes | WebP bytes | File reduction | Original transfer ms | WebP transfer ms | Transfer-time reduction |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
${productImages.map((row,index)=>{const original=imageTransfers.results.find(v=>v.path===row.path.slice(0,-5)),optimized=imageTransfers.results.find(v=>v.path===row.path);return `| ${index+1} | ${row.original_bytes} | ${row.optimized_bytes} | ${percent(row.original_bytes,row.optimized_bytes)} | ${original.request_ms.toFixed(1)} | ${optimized.request_ms.toFixed(1)} | ${percent(original.request_ms,optimized.request_ms)} |`}).join('\n')}

Combined product-file reduction: ${percent(productImages.reduce((s,r)=>s+r.original_bytes,0),productImages.reduce((s,r)=>s+r.optimized_bytes,0))}. [Conversion evidence](product-image-optimization.json), [real encoded browser transfer sizes/times](product-image-transfers.json).

Existing uploads were converted using media:optimize --directory=products; this scans local files and does not query product/customer tables. Derivatives are written to temporary files and moved into place before they become selectable. Originals remain available for rollback. New uploads retain their detected original extension/path; URL selection prefers a ready derivative on local disks and otherwise falls back. Cloud disks incur no derivative existence probe. GD/WebP is optional: unsupported disks/encoders, small files, files above 10 MiB/8 megapixels, detected animated PNG/WebP files and EXIF-oriented JPEGs are kept as originals. Optional encoding/storage errors do not discard a successful original upload. Media deletion/failed-upload cleanup handles local sidecars.

No price cache was added. Media uses new UUID paths for new uploads, and derivative URLs differ from original URLs; this provides versioned cache keys. Existing originals stay byte-for-byte intact, existing media API responses are not persistently cached here, and deleting media removes the variant. Public variant caching should use immutable versioned paths. Deleting a variant restores URL fallback without rewriting database rows.

The intermediate **images-only** build was measured on Home/Menu/Product/Login before the request/stability changes: [results](after-assets-production.json). Home median LCP fell from 14,524 to 3,520 ms with the same ten initial API calls. Product CLS remained about 0.399, isolating its layout cause from image transfer.
`
for (const mode of ['production','development']) {
  const before = await read('before-'+mode), after = await read('after-'+mode)
  if (before.pages.length !== 33 || after.pages.length !== 33) throw Error('Incomplete '+mode+' measurement')
  doc += `\n## ${mode === 'production' ? 'Production build' : 'Development server'} — before → after (reduction)\n\n| Page | LCP ms | FCP ms | CLS | HTML TTFB ms |\n| --- | --- | --- | --- | --- |\n`
  for(const name of names) doc += `| ${name} | ${['lcp','fcp','cls','ttfb'].map(key=>cell(before.pages,after.pages,name,key)).join(' | ')} |\n`
  doc += '\n| Page | JS KiB | CSS KiB | Images KiB | API requests |\n| --- | --- | --- | --- | --- |\n'
  for(const name of names) doc += `| ${name} | ${['js_bytes','css_bytes','image_bytes','api_count'].map(key=>cell(before.pages,after.pages,name,key)).join(' | ')} |\n`
  doc += '\n| Page | Local interaction proxy ms (not field INP) |\n| --- | --- |\n'
  for(const name of names) doc += `| ${name} | ${cell(before.pages,after.pages,name,'interaction_ms')} |\n`
  doc += `\nEvidence: [before JSON](before-${mode}.json), [after JSON](after-${mode}.json). Browser diagnostics: before ${before.diagnostics.length}, after ${after.diagnostics.length}; overflow cases: before ${before.pages.filter(p=>p.overflow).length}, after ${after.pages.filter(p=>p.overflow).length}.\n`
}
doc += `
### Interpretation of regressions and unchanged results

Login's measured LCP increases: its before candidate was H1, while the compressed image becomes the auth-food candidate visible near the bottom of the mobile viewport. This candidate change is recorded honestly; reduced bytes do not guarantee a smaller LCP value for every page. The final form design was not moved or hidden to manipulate the metric. Pages whose JS/CSS sizes or API counts did not change are not claimed as bundle/endpoint improvements. Admin input timing samples are too few and too variable to establish INP improvement. The raw data shows polling and any local variability.

## Laravel and database measurements

Each engine/endpoint has seven actual localhost HTTP measurements, without browser network/CPU throttling. The same empty customer-cart state is enforced for API-only measurements, independently of the populated browser cart. Initial application setup is excluded. These API timings cannot be compared directly with queued, network-throttled browser API timings.

The audit router bootstraps Laravel, then attaches DB::listen before handling the request. Server-Timing separates total application time, bootstrap time and summed query elapsed time. **DB::listen includes PDO transport/fetch overhead**; it is not MySQL server-only execution time, and bootstrap database work is included in bootstrap rather than the later query listener. Non-DB handling is calculated per sample as app − boot − db. HTTP minus app is local transport/server overhead, not a measurement of Internet latency. No timing middleware is shipped in the production application.

| Engine | Endpoint | HTTP ms before → after (%) | App ms before → after (%) | Boot ms before → after (%) | PDO query ms before → after (%) | Queries before → after | Non-DB handling ms before → after | Payload bytes before → after |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
`
for(const before of beforeApi){
  const after=afterApi.find(row=>row.engine===before.engine&&row.path===before.path)
  const p=(rows,key)=>median(rows.map(row=>key==='http'?row.ms:key==='bytes'?row.bytes:key==='handling'?timing(row,'app')-timing(row,'boot')-timing(row,'db'):timing(row,key)))
  const pair=key=>{const a=p(before.samples,key),b=p(after.samples,key);return `${a.toFixed(2)} → ${b.toFixed(2)} (${percent(a,b)})`}
  doc+=`| ${before.engine} | ${before.path} | ${['http','app','boot','db'].map(pair).join(' | ')} | ${p(before.samples,'queries')} → ${p(after.samples,'queries')} | ${p(before.samples,'handling').toFixed(2)} → ${p(after.samples,'handling').toFixed(2)} | ${p(before.samples,'bytes')} → ${p(after.samples,'bytes')} |\n`
}
doc+=`
Backend SQL did not change. Media serialization now selects ready derivatives, but these seeded products contain no image rows. Percentage differences here are repeated-measurement variability, not attributed SQL speedups. MySQL/MariaDB and SQLite comparisons are sequential local measurements of a small synthetic fixture, not evidence that one database is universally faster. Final API timing was repeated after the media change; page tables measure the frontend changes, while actual product-file transfers are reported separately.

Actual MariaDB server-side SQL execution was additionally measured with SHOW PROFILES, separate from PDO timing: admin business-day SELECT ${(plans.server_profiles[1].Duration*1000).toFixed(3)} ms, branch-menu SELECT ${(plans.server_profiles[3].Duration*1000).toFixed(3)} ms, driver-queue SELECT ${(plans.server_profiles[5].Duration*1000).toFixed(3)} ms. These are representative SELECTs, not full endpoint traces. [EXPLAIN/index/server-profile evidence](mysql-plans.json).

- The business-day plan uses orders_branch_id_placed_at_index. The driver example uses an existing status/time index. The branch-product plan scans five rows despite available indexes; adding an index for a five-row fixture would be unsubstantiated.
- MenuService batches branch products and eager loads category/primary image; product options are loaded for detail, not every public card. Admin orders eager load required relationships and paginate. Existing MenuTest and OrderTrackingPerformanceTest verify query counts do not scale per product/category/option/item and that tracking status is fresh.
- Bootstrap and non-SQL PHP processing dominate these local endpoint measurements. The single-worker server further serializes parallel browser requests. No persistent price, inventory, payment or order-status cache was added; therefore no new invalidation policy is required. Existing public-settings caching/forced mutation refreshes are preserved. Branch changes trigger fresh Home/menu data, and checkout remains validated by Laravel.
- Sanctum/authorization were exercised with genuine customer/admin/driver tokens. The customer /admin/me 403 is expected role detection, not a broken checkout request. Permissions were not bypassed to improve performance.

## Vue/router/loading review

Public pages and AdminLayout/DriverLayout already use route-level dynamic imports. CDP resource lists confirm admin view chunks do not load on Home/Menu/Product and Leaflet does not load until a map is mounted; it remains in a separate ~149 KB raw build chunk. No speculative manual chunk splitting was introduced. The small global settings stylesheet includes public operation-status styling; moving it wholesale into admin would break public presentation.

Pinia branch/settings requests are shared. Menu searches remain debounced and use AbortController plus revision checks through useApiState. Closing search clears the query; default Home uses the CMS response rather than firing an empty menu search. Branch changes discard old price data and fetch again. Layout watchers/polling were reviewed; intended order polling remains active. A skeleton alone was not used to claim faster loading: image transfer and duplicate requests were reduced, while loading geometry was fixed separately for CLS.

## Validation

- Frontend build: ${verification.build}.
- Laravel tests: ${verification.laravel}.
- Pint: ${verification.pint}.
- Final Home resilience check: published CMS makes no redundant menu/gallery/category requests; an unpublished CMS (HTTP 404) triggers one fallback menu and one gallery request and still renders products. Nonempty search makes one debounced request; clearing search reuses the CMS result. [Browser evidence](home-fallback.json). These fallback calls occur only on CMS failure.
- The eight skipped Laravel cases require opt-in MySQL/concurrency test configuration; their skipped status is retained, not counted as executed passes. Audit bootstraps refuse cached application configuration to prevent accidental use of the normal database.
- Real MySQL-backed Vue/Axios order journey: **${journey.result}**, ${journey.pages.length} pages and ${journey.responsive.length} viewport checks (320–1920 px). [Journey evidence](journey.json).
- Journey checks: ${journey.checks.join('; ')}.
- Before/after screenshots are saved for every measured page. Mobile Home/Product/Login visual checks retained identity, colors, RTL and final geometry; branch loading is intentionally more stable. The Şişli reference directory was not edited.
- An additional public photograph was linked only to the synthetic product after all timings. The production Vue Product page successfully loaded the real optimized Laravel URL with unchanged image resource keys, no JS diagnostics and no overflow: [functional evidence](photo-functional.json), [screenshot](photo-functional.png).
- Cleanup: [synthetic schema/file/private manifest removal](cleanup.json); owned audit browsers/servers were stopped. Existing application processes and uploaded originals were retained.
- No API endpoints, database tables, migrations, prices, coupon eligibility or payment behavior were changed. BACKEND → FRONTEND COVERAGE: existing /home featured_products and gallery_preview now feed Home; existing public settings feed Checkout via Pinia; existing branch/product APIs feed the stable Product loading state; existing ProductImageResource/BannerResource/GalleryItemResource URL fields serve eligible local variants without frontend contract changes. Media tests validate dimensions, alpha, fallback, upload resilience, deletion and unchanged resource keys.

## Remaining issues and recommendations before deployment

1. The three uploaded photographs now have WebP variants, but responsive widths/srcset and AVIF are not implemented. Add actual generated widths and a reviewed way to convey dimensions, then compare on staging with real catalog photographs. Do not invent variant URLs without files. Confirm PHP GD/WebP support and public storage/symlink access on deployment; unsupported encoders/disks fall back to originals. Review oriented JPEGs, very large images and remote media storage separately.
   Existing uploaded files are outside source control: generate eligible product derivatives on the deployment filesystem with \`php artisan media:optimize --disk=public --directory=products\`. This scans files without querying customer data and retains originals; newly uploaded eligible media is optimized through MediaService.
2. Test deployment against the actual production MySQL version and realistically sized sanitized fixtures. The small local dataset cannot rule out slow reports/queries at production scale. Retain query-count tests and sample real slow-query logs on staging.
3. Serve production Vite output, not the development server. Use PHP-FPM with OPcache and reviewed Laravel config/route caches on staging; the audit deliberately did not modify the user's global cache or services. Test workers/concurrency before claiming API capacity.
4. Configure immutable caching for hashed frontend assets and suitable CDN/storage cache headers for versioned media. Verify Brotli/gzip, HTTPS and actual Internet TTFB. Apply content invalidation on media URL changes; do not cache branch prices/availability/status without a tested mutation-aware policy.
5. Obtain Lighthouse runs if installed and field Web Vitals after deployment. Prioritize real INP for add-to-cart/checkout/admin actions, representative low-end phones, and LCP with actual uploaded product photos. The local focus proxy is not a substitute for those field measurements.
6. Google-hosted Tajawal uses display=swap and preconnect. Local inventory contains no duplicate font bundle; external font transfer is captured in browser resource lists. Consider licensed local hosting only after measuring that external connection on the production network.
7. Login LCP candidate behavior and small CLS/polling differences remain visible in the tables; investigate on real mobile devices rather than hiding elements to improve scores.

## Reproduction and evidence

Audit utilities: back-end/tests/Support/performance-setup.php (creates unique test schema/SQLite file and private token manifest); performance-router.php (guarded audit-only timing server); performance-inspect.php (EXPLAIN/server profiling); performance-inventory.php; performance-images.php (explicit shipped-asset compression); performance-media.php (guarded file-only product conversion); performance-cleanup.php (strictly guarded synthetic database/token removal). Frontend tests/performance-audit.mjs, performance-api.mjs, performance-trace.mjs and performance-journey.browser.mjs use real APIs; performance-product-images.mjs measures static files without Laravel. All fixtures/tokens stay in the ignored private directory; synthetic tokens become invalid when the disposable schema is removed. Audit build output directories are ignored and separate from normal dist.

Build with VITE_API_BASE_URL=http://127.0.0.1:8019/api/v1 into an isolated output directory, then preview with --host 127.0.0.1 --port 4179 --strictPort. Development uses the same API and --host 127.0.0.1 --port 5179 --strictPort. Use node --experimental-websocket tests/performance-audit.mjs with PERF_STAGE=before/after and PERF_MODE=production/development. The saved before output is a frozen pre-change build. Run workloads sequentially; keep fixtures, browser settings and observation protocol identical. Database setup and cleanup must remain restricted to the generated audit schema, never the normal .env database. Re-running the compression utility overwrites its asset-size evidence; archive a baseline first.

Raw evidence: [before API](before-api.json), [after API](after-api.json), [before Chrome Performance trace](before-chrome-trace.json), [after Chrome Performance trace](after-chrome-trace.json), [before Chrome metrics/layout sources](before-chrome-metrics.json), [after Chrome metrics/layout sources](after-chrome-metrics.json), [verification](verification.json). Import Chrome trace JSON through the DevTools Performance panel.

Method references: [Chrome Performance tooling](https://developer.chrome.com/docs/devtools/performance/reference/), [INP definition and field interpretation](https://web.dev/articles/inp), [Laravel database query monitoring](https://laravel.com/framework/docs/database/config).
`
await writeFile(root+'PERFORMANCE_AUDIT.md',doc)
console.log('Wrote full measured performance audit')
