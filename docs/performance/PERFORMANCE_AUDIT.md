# SHISHLI — Performance audit and optimization

Audit date: 2026-10-10. This report describes measured local behavior, not production field performance.

## Environment and safety

- Windows/XAMPP, PHP 8.3.29, Laravel 13.33.0; Node 20.19.0, Vite 8.3.2, Vue 3.5.42; Chrome 154.0.8037.98.
- The available MySQL-protocol server is **10.4.25-MariaDB**. This is MariaDB, not Oracle MySQL. PDO MySQL was measured separately from SQLite. Docker CLI is installed, but both sandboxed and unrestricted checks found its engine unavailable, so an Oracle MySQL container could not be run in the current environment; Oracle MySQL production behavior still requires staging verification.
- Two newly seeded isolated databases were used. Initial fixture: 5 products, 28 orders, 11 users. No live customer database was queried or modified. Public upload files were inspected as files only.
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
| logo/shishli-logo | 783022 | 39350 | 95.0% | 480×320, WebP q90 |
| banners/restaurant-reference | 1644974 | 239762 | 85.4% | 1536×1024, WebP q84 |

[Full image/font/icon inventory](asset-inventory.json) inspected 17 assets and found 0 byte-identical groups. Source PNG and WebP versions are source/served variants, not duplicate transfers. Product-card images already use lazy loading and reserved aspect-ratio frames. Home/LCP assets are not lazy loaded. Responsive srcset variants remain a later improvement; the existing large product images now use smaller full-size WebP variants.

### Existing product images — separate static-file comparison

The original files were retained unchanged, allowing an original-versus-optimized comparison after conversion without using a customer database. Chrome loaded each file once through a static-only PHP server under the same mobile network/CPU settings. This is an isolated transfer experiment, **not Product page LCP**; it is not mixed into the placeholder-product page tables.

| Photograph | Original file bytes | WebP bytes | File reduction | Original transfer ms | WebP transfer ms | Transfer-time reduction |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 2844934 | 382878 | 86.5% | 16258.1 | 2110.7 | 87.0% |
| 2 | 2803425 | 295704 | 89.5% | 14770.2 | 1633.6 | 88.9% |
| 3 | 2767574 | 306638 | 88.9% | 14346.0 | 1705.9 | 88.1% |

Combined product-file reduction: 88.3%. [Conversion evidence](product-image-optimization.json), [real encoded browser transfer sizes/times](product-image-transfers.json).

Existing uploads were converted using media:optimize --directory=products; this scans local files and does not query product/customer tables. Derivatives are written to temporary files and moved into place before they become selectable. Originals remain available for rollback. New uploads retain their detected original extension/path; URL selection prefers a ready derivative on local disks and otherwise falls back. Cloud disks incur no derivative existence probe. GD/WebP is optional: unsupported disks/encoders, small files, files above 10 MiB/8 megapixels, detected animated PNG/WebP files and EXIF-oriented JPEGs are kept as originals. Optional encoding/storage errors do not discard a successful original upload. Media deletion/failed-upload cleanup handles local sidecars.

No price cache was added. Media uses new UUID paths for new uploads, and derivative URLs differ from original URLs; this provides versioned cache keys. Existing originals stay byte-for-byte intact, existing media API responses are not persistently cached here, and deleting media removes the variant. Public variant caching should use immutable versioned paths. Deleting a variant restores URL fallback without rewriting database rows.

The intermediate **images-only** build was measured on Home/Menu/Product/Login before the request/stability changes: [results](after-assets-production.json). Home median LCP fell from 14,524 to 3,520 ms with the same ten initial API calls. Product CLS remained about 0.399, isolating its layout cause from image transfer.

## Production build — before → after (reduction)

| Page | LCP ms | FCP ms | CLS | HTML TTFB ms |
| --- | --- | --- | --- | --- |
| Home | 14524.0 → 3752.0 (74.2%) | 1668.0 → 1836.0 (-10.1%) | 0.0003 → 0.0003 (3.3%) | 3.3 → 4.3 (-30.3%) |
| Menu | 1548.0 → 1676.0 (-8.3%) | 1548.0 → 1676.0 (-8.3%) | 0.0253 → 0.0184 (27.3%) | 3.8 → 2.7 (28.9%) |
| Product | 4480.0 → 4360.0 (2.7%) | 1472.0 → 1460.0 (0.8%) | 0.3995 → 0.0789 (80.3%) | 5.1 → 3.6 (29.4%) |
| Cart | 6800.0 → 3012.0 (55.7%) | 2704.0 → 2648.0 (2.1%) | 0.0083 → 0.0097 (-16.9%) | 2.6 → 3.0 (-15.4%) |
| Checkout | 5236.0 → 4464.0 (14.7%) | 3016.0 → 2752.0 (8.8%) | 0.0070 → 0.0321 (-356.0%) | 3.0 → 2.1 (30.0%) |
| Login | 1412.0 → 4148.0 (-193.8%) | 1412.0 → 1392.0 (1.4%) | 0.0003 → 0.0003 (17.3%) | 3.6 → 2.8 (22.2%) |
| Addresses | 7116.0 → 3004.0 (57.8%) | 3020.0 → 2584.0 (14.4%) | 0.0053 → 0.0016 (70.1%) | 3.2 → 2.9 (9.4%) |
| Tracking | 7240.0 → 3188.0 (56.0%) | 2976.0 → 2820.0 (5.2%) | 0.0062 → 0.0074 (-18.9%) | 4.0 → 4.0 (0.0%) |
| Admin Dashboard | 3344.0 → 3296.0 (1.4%) | 3344.0 → 3296.0 (1.4%) | 0.0676 → 0.0675 (0.1%) | 2.4 → 2.9 (-20.8%) |
| Admin Orders | 7572.0 → 6368.0 (15.9%) | 4300.0 → 3448.0 (19.8%) | 0.0304 → 0.0304 (0.0%) | 3.8 → 2.6 (31.6%) |
| Driver Orders | 4300.0 → 3232.0 (24.8%) | 4300.0 → 3232.0 (24.8%) | 0.0042 → 0.0042 (0.0%) | 2.3 → 2.5 (-8.7%) |

| Page | JS KiB | CSS KiB | Images KiB | API requests |
| --- | --- | --- | --- | --- |
| Home | 115.5 → 115.6 (-0.1%) | 17.8 → 18.1 (-2.0%) | 2371.6 → 273.1 (88.5%) | 10 → 7 (30.0%) |
| Menu | 107.4 → 107.4 (0.0%) | 17.8 → 17.8 (-0.0%) | 764.9 → 38.7 (94.9%) | 8 → 8 (0.0%) |
| Product | 106.5 → 106.5 (-0.0%) | 17.8 → 17.8 (-0.0%) | 764.9 → 38.7 (94.9%) | 7 → 7 (0.0%) |
| Cart | 97.9 → 97.9 (0.0%) | 17.8 → 17.8 (-0.0%) | 764.9 → 38.7 (94.9%) | 6 → 6 (0.0%) |
| Checkout | 119.1 → 119.0 (0.0%) | 21.4 → 21.4 (-0.0%) | 764.9 → 38.7 (94.9%) | 7 → 6 (14.3%) |
| Login | 100.9 → 100.9 (0.0%) | 17.8 → 17.8 (-0.0%) | 2371.6 → 273.1 (88.5%) | 5 → 5 (0.0%) |
| Addresses | 107.6 → 107.6 (0.0%) | 20.5 → 20.5 (-0.0%) | 764.9 → 38.7 (94.9%) | 7 → 7 (0.0%) |
| Tracking | 111.3 → 111.3 (0.0%) | 22.3 → 22.3 (-0.0%) | 764.9 → 38.7 (94.9%) | 6 → 6 (0.0%) |
| Admin Dashboard | 124.6 → 124.6 (0.0%) | 30.7 → 30.7 (-0.0%) | 764.9 → 38.7 (94.9%) | 9 → 9 (0.0%) |
| Admin Orders | 125.3 → 125.3 (0.0%) | 30.4 → 30.4 (-0.0%) | 764.9 → 38.7 (94.9%) | 7 → 7 (0.0%) |
| Driver Orders | 103.4 → 103.4 (0.0%) | 19.8 → 19.9 (-0.1%) | 0.0 → 0.0 (N/A) | 5 → 5 (0.0%) |

| Page | Local interaction proxy ms (not field INP) |
| --- | --- |
| Home | N/A → N/A (N/A) |
| Menu | 32.0 → 88.0 (-175.0%) |
| Product | N/A → N/A (N/A) |
| Cart | N/A → N/A (N/A) |
| Checkout | N/A → N/A (N/A) |
| Login | 16.0 → 24.0 (-50.0%) |
| Addresses | N/A → N/A (N/A) |
| Tracking | N/A → N/A (N/A) |
| Admin Dashboard | 152.0 → 80.0 (47.4%) |
| Admin Orders | 312.0 → 240.0 (23.1%) |
| Driver Orders | N/A → N/A (N/A) |

Evidence: [before JSON](before-production.json), [after JSON](after-production.json). Browser diagnostics: before 0, after 0; overflow cases: before 0, after 0.

## Development server — before → after (reduction)

| Page | LCP ms | FCP ms | CLS | HTML TTFB ms |
| --- | --- | --- | --- | --- |
| Home | 34852.0 → 24248.0 (30.4%) | 22000.0 → 22224.0 (-1.0%) | 0.0003 → 0.0003 (3.0%) | 123.0 → 146.9 (-19.4%) |
| Menu | 21288.0 → 21820.0 (-2.5%) | 21288.0 → 21820.0 (-2.5%) | 0.0357 → 0.0175 (50.9%) | 99.5 → 145.3 (-46.0%) |
| Product | 24520.0 → 24552.0 (-0.1%) | 21312.0 → 21692.0 (-1.8%) | 0.4012 → 0.0934 (76.7%) | 107.4 → 110.3 (-2.7%) |
| Cart | 25968.0 → 22236.0 (14.4%) | 21812.0 → 21880.0 (-0.3%) | 0.0088 → 0.0054 (38.6%) | 107.5 → 123.8 (-15.2%) |
| Checkout | 26728.0 → 26520.0 (0.8%) | 24240.0 → 24592.0 (-1.5%) | 0.0036 → 0.0151 (-321.6%) | 130.6 → 101.5 (22.3%) |
| Login | 20664.0 → 20808.0 (-0.7%) | 20664.0 → 20736.0 (-0.3%) | 0.0003 → 0.0003 (0.0%) | 111.2 → 91.2 (18.0%) |
| Addresses | 27324.0 → 23968.0 (12.3%) | 23200.0 → 23620.0 (-1.8%) | 0.0016 → 0.0057 (-254.7%) | 116.8 → 122.6 (-5.0%) |
| Tracking | 27532.0 → 23516.0 (14.6%) | 23160.0 → 23092.0 (0.3%) | 0.0071 → 0.0005 (93.5%) | 105.2 → 106.7 (-1.4%) |
| Admin Dashboard | 24656.0 → 24564.0 (0.4%) | 24656.0 → 24564.0 (0.4%) | 0.0687 → 0.0675 (1.7%) | 152.5 → 156.4 (-2.6%) |
| Admin Orders | 27756.0 → 27844.0 (-0.3%) | 24516.0 → 24752.0 (-1.0%) | 0.0304 → 0.0304 (0.0%) | 150.7 → 174.1 (-15.5%) |
| Driver Orders | 22824.0 → 22884.0 (-0.3%) | 22824.0 → 22884.0 (-0.3%) | 0.0049 → 0.0042 (14.8%) | 126.9 → 132.4 (-4.3%) |

| Page | JS KiB | CSS KiB | Images KiB | API requests |
| --- | --- | --- | --- | --- |
| Home | 3974.2 → 3979.9 (-0.1%) | 1.1 → 1.1 (0.0%) | 2371.6 → 273.1 (88.5%) | 10 → 7 (30.0%) |
| Menu | 3855.3 → 3854.7 (0.0%) | 0.7 → 0.7 (0.0%) | 764.9 → 38.7 (94.9%) | 8 → 8 (0.0%) |
| Product | 3861.0 → 3860.7 (0.0%) | 0.7 → 0.7 (0.0%) | 764.9 → 38.7 (94.9%) | 7 → 7 (0.0%) |
| Cart | 3747.3 → 3747.7 (-0.0%) | 0.7 → 0.7 (0.0%) | 764.9 → 38.7 (94.9%) | 6 → 6 (0.0%) |
| Checkout | 4210.7 → 4211.2 (-0.0%) | 0.7 → 0.7 (0.0%) | 764.9 → 38.7 (94.9%) | 7 → 6 (14.3%) |
| Login | 3788.1 → 3788.6 (-0.0%) | 0.7 → 0.7 (0.0%) | 2371.6 → 273.1 (88.5%) | 5 → 5 (0.0%) |
| Addresses | 3941.6 → 3942.1 (-0.0%) | 0.7 → 0.7 (3.0%) | 764.9 → 38.7 (94.9%) | 7 → 7 (0.0%) |
| Tracking | 3947.6 → 3948.1 (-0.0%) | 0.7 → 0.7 (0.0%) | 764.9 → 38.7 (94.9%) | 6 → 6 (0.0%) |
| Admin Dashboard | 4227.9 → 4228.4 (-0.0%) | 0.7 → 0.7 (0.0%) | 764.9 → 38.7 (94.9%) | 9 → 9 (0.0%) |
| Admin Orders | 4171.6 → 4172.1 (-0.0%) | 0.7 → 0.7 (3.1%) | 764.9 → 38.7 (94.9%) | 7 → 7 (0.0%) |
| Driver Orders | 3827.9 → 3828.3 (-0.0%) | 0.8 → 0.7 (0.8%) | 0.0 → 0.0 (N/A) | 5 → 5 (0.0%) |

| Page | Local interaction proxy ms (not field INP) |
| --- | --- |
| Home | N/A → N/A (N/A) |
| Menu | 32.0 → 24.0 (25.0%) |
| Product | N/A → N/A (N/A) |
| Cart | N/A → N/A (N/A) |
| Checkout | N/A → N/A (N/A) |
| Login | 24.0 → 16.0 (33.3%) |
| Addresses | N/A → N/A (N/A) |
| Tracking | N/A → N/A (N/A) |
| Admin Dashboard | 232.0 → 224.0 (3.4%) |
| Admin Orders | 376.0 → 376.0 (0.0%) |
| Driver Orders | N/A → N/A (N/A) |

Evidence: [before JSON](before-development.json), [after JSON](after-development.json). Browser diagnostics: before 0, after 0; overflow cases: before 0, after 0.

### Interpretation of regressions and unchanged results

Login's measured LCP increases: its before candidate was H1, while the compressed image becomes the auth-food candidate visible near the bottom of the mobile viewport. This candidate change is recorded honestly; reduced bytes do not guarantee a smaller LCP value for every page. The final form design was not moved or hidden to manipulate the metric. Pages whose JS/CSS sizes or API counts did not change are not claimed as bundle/endpoint improvements. Admin input timing samples are too few and too variable to establish INP improvement. The raw data shows polling and any local variability.

## Laravel and database measurements

Each engine/endpoint has seven actual localhost HTTP measurements, without browser network/CPU throttling. The same empty customer-cart state is enforced for API-only measurements, independently of the populated browser cart. Initial application setup is excluded. These API timings cannot be compared directly with queued, network-throttled browser API timings.

The audit router bootstraps Laravel, then attaches DB::listen before handling the request. Server-Timing separates total application time, bootstrap time and summed query elapsed time. **DB::listen includes PDO transport/fetch overhead**; it is not MySQL server-only execution time, and bootstrap database work is included in bootstrap rather than the later query listener. Non-DB handling is calculated per sample as app − boot − db. HTTP minus app is local transport/server overhead, not a measurement of Internet latency. No timing middleware is shipped in the production application.

| Engine | Endpoint | HTTP ms before → after (%) | App ms before → after (%) | Boot ms before → after (%) | PDO query ms before → after (%) | Queries before → after | Non-DB handling ms before → after | Payload bytes before → after |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| mysql | /api/v1/home?branch=:branch | 284.32 → 286.42 (-0.7%) | 275.04 → 278.23 (-1.2%) | 149.23 → 138.55 (7.2%) | 24.79 → 40.54 (-63.5%) | 17 → 17 | 103.96 → 99.72 | 9146 → 9146 |
| mysql | /api/v1/menu?branch=:branch | 224.93 → 268.32 (-19.3%) | 217.43 → 262.04 (-20.5%) | 133.47 → 143.04 (-7.2%) | 7.57 → 25.97 (-243.1%) | 9 → 9 | 76.74 → 89.41 | 6299 → 6299 |
| mysql | /api/v1/menu/products/:product?branch=:branch | 215.41 → 235.51 (-9.3%) | 210.18 → 229.82 (-9.3%) | 125.93 → 126.08 (-0.1%) | 7.29 → 27.94 (-283.3%) | 9 → 9 | 69.74 → 69.84 | 1067 → 1067 |
| mysql | /api/v1/branches | 195.12 → 199.77 (-2.4%) | 189.43 → 193.72 (-2.3%) | 129.43 → 127.80 (1.3%) | 5.48 → 16.37 (-198.7%) | 1 → 1 | 48.31 → 45.38 | 729 → 729 |
| mysql | /api/v1/cart | 242.16 → 235.18 (2.9%) | 235.94 → 230.41 (2.3%) | 136.46 → 129.10 (5.4%) | 17.29 → 23.98 (-38.7%) | 11 → 11 | 77.98 → 74.82 | 680 → 680 |
| mysql | /api/v1/addresses | 202.00 → 205.00 (-1.5%) | 194.84 → 199.62 (-2.5%) | 131.69 → 126.64 (3.8%) | 11.77 → 18.97 (-61.2%) | 3 → 3 | 53.39 → 51.48 | 578 → 578 |
| mysql | /api/v1/orders/:order/tracking | 225.57 → 218.25 (3.2%) | 220.13 → 212.69 (3.4%) | 134.67 → 127.07 (5.6%) | 19.48 → 16.24 (16.6%) | 8 → 8 | 62.95 → 62.16 | 2406 → 2406 |
| mysql | /api/v1/admin/dashboard | 339.30 → 266.70 (21.4%) | 332.20 → 260.35 (21.6%) | 167.72 → 130.28 (22.3%) | 32.82 → 24.19 (26.3%) | 14 → 14 | 137.15 → 105.02 | 3228 → 3228 |
| mysql | /api/v1/admin/orders | 408.63 → 278.02 (32.0%) | 401.30 → 271.75 (32.3%) | 174.43 → 125.95 (27.8%) | 36.52 → 20.23 (44.6%) | 13 → 13 | 180.17 → 127.95 | 8876 → 8876 |
| mysql | /api/v1/driver/orders | 365.15 → 275.56 (24.5%) | 358.70 → 270.74 (24.5%) | 167.65 → 132.60 (20.9%) | 37.56 → 24.32 (35.3%) | 15 → 14 | 143.02 → 110.64 | 1469 → 1469 |
| sqlite | /api/v1/home?branch=:branch | 333.07 → 235.14 (29.4%) | 324.10 → 229.18 (29.3%) | 186.02 → 131.76 (29.2%) | 7.00 → 5.57 (20.4%) | 17 → 17 | 130.96 → 92.03 | 9146 → 9146 |
| sqlite | /api/v1/menu?branch=:branch | 288.22 → 289.32 (-0.4%) | 279.65 → 281.69 (-0.7%) | 174.66 → 172.44 (1.3%) | 4.45 → 4.84 (-8.8%) | 9 → 9 | 101.46 → 102.48 | 6299 → 6299 |
| sqlite | /api/v1/menu/products/:product?branch=:branch | 282.49 → 291.50 (-3.2%) | 276.58 → 284.95 (-3.0%) | 178.44 → 179.54 (-0.6%) | 4.40 → 5.09 (-15.7%) | 9 → 9 | 93.74 → 98.66 | 1067 → 1067 |
| sqlite | /api/v1/branches | 245.24 → 242.27 (1.2%) | 238.47 → 235.63 (1.2%) | 173.26 → 172.38 (0.5%) | 2.87 → 2.93 (-2.1%) | 1 → 1 | 61.36 → 60.59 | 729 → 729 |
| sqlite | /api/v1/cart | 293.72 → 294.73 (-0.3%) | 287.83 → 287.49 (0.1%) | 169.70 → 169.73 (-0.0%) | 7.55 → 6.13 (18.8%) | 11 → 11 | 96.24 → 99.23 | 680 → 680 |
| sqlite | /api/v1/addresses | 244.02 → 237.26 (2.8%) | 237.38 → 231.71 (2.4%) | 164.30 → 163.18 (0.7%) | 3.48 → 3.33 (4.3%) | 3 → 3 | 67.44 → 63.97 | 578 → 578 |
| sqlite | /api/v1/orders/:order/tracking | 259.68 → 249.33 (4.0%) | 253.36 → 243.21 (4.0%) | 166.33 → 162.60 (2.2%) | 4.52 → 4.11 (9.1%) | 8 → 8 | 76.65 → 75.98 | 2413 → 2413 |
| sqlite | /api/v1/admin/dashboard | 319.98 → 319.38 (0.2%) | 312.13 → 312.06 (0.0%) | 165.41 → 161.32 (2.5%) | 8.43 → 6.63 (21.4%) | 14 → 14 | 138.90 → 130.10 | 3228 → 3228 |
| sqlite | /api/v1/admin/orders | 349.50 → 341.41 (2.3%) | 341.25 → 335.58 (1.7%) | 163.87 → 163.50 (0.2%) | 6.95 → 6.72 (3.3%) | 13 → 13 | 164.10 → 163.01 | 8876 → 8876 |
| sqlite | /api/v1/driver/orders | 328.73 → 321.05 (2.3%) | 322.66 → 313.82 (2.7%) | 160.05 → 158.04 (1.3%) | 6.31 → 7.34 (-16.3%) | 14 → 14 | 142.06 → 135.97 | 1469 → 1469 |

Backend SQL did not change. Media serialization now selects ready derivatives, but these seeded products contain no image rows. Percentage differences here are repeated-measurement variability, not attributed SQL speedups. MySQL/MariaDB and SQLite comparisons are sequential local measurements of a small synthetic fixture, not evidence that one database is universally faster. Final API timing was repeated after the media change; page tables measure the frontend changes, while actual product-file transfers are reported separately.

Actual MariaDB server-side SQL execution was additionally measured with SHOW PROFILES, separate from PDO timing: admin business-day SELECT 0.685 ms, branch-menu SELECT 0.267 ms, driver-queue SELECT 0.384 ms. These are representative SELECTs, not full endpoint traces. [EXPLAIN/index/server-profile evidence](mysql-plans.json).

- The business-day plan uses orders_branch_id_placed_at_index. The driver example uses an existing status/time index. The branch-product plan scans five rows despite available indexes; adding an index for a five-row fixture would be unsubstantiated.
- MenuService batches branch products and eager loads category/primary image; product options are loaded for detail, not every public card. Admin orders eager load required relationships and paginate. Existing MenuTest and OrderTrackingPerformanceTest verify query counts do not scale per product/category/option/item and that tracking status is fresh.
- Bootstrap and non-SQL PHP processing dominate these local endpoint measurements. The single-worker server further serializes parallel browser requests. No persistent price, inventory, payment or order-status cache was added; therefore no new invalidation policy is required. Existing public-settings caching/forced mutation refreshes are preserved. Branch changes trigger fresh Home/menu data, and checkout remains validated by Laravel.
- Sanctum/authorization were exercised with genuine customer/admin/driver tokens. The customer /admin/me 403 is expected role detection, not a broken checkout request. Permissions were not bypassed to improve performance.

## Vue/router/loading review

Public pages and AdminLayout/DriverLayout already use route-level dynamic imports. CDP resource lists confirm admin view chunks do not load on Home/Menu/Product and Leaflet does not load until a map is mounted; it remains in a separate ~149 KB raw build chunk. No speculative manual chunk splitting was introduced. The small global settings stylesheet includes public operation-status styling; moving it wholesale into admin would break public presentation.

Pinia branch/settings requests are shared. Menu searches remain debounced and use AbortController plus revision checks through useApiState. Closing search clears the query; default Home uses the CMS response rather than firing an empty menu search. Branch changes discard old price data and fetch again. Layout watchers/polling were reviewed; intended order polling remains active. A skeleton alone was not used to claim faster loading: image transfer and duplicate requests were reduced, while loading geometry was fixed separately for CLS.

## Validation

- Frontend build: Passed: npm run build, 349 modules, Vite 8.3.2.
- Laravel tests: Passed: 2100 passed, 8 skipped, 2108 total, 12273 assertions; 1989980 ms. Final media follow-up: 6 passed, 32 assertions..
- Pint: Passed: scoped dirty PHP files, checked after formatting.
- Final Home resilience check: published CMS makes no redundant menu/gallery/category requests; an unpublished CMS (HTTP 404) triggers one fallback menu and one gallery request and still renders products. Nonempty search makes one debounced request; clearing search reuses the CMS result. [Browser evidence](home-fallback.json). These fallback calls occur only on CMS failure.
- The eight skipped Laravel cases require opt-in MySQL/concurrency test configuration; their skipped status is retained, not counted as executed passes. Audit bootstraps refuse cached application configuration to prevent accidental use of the normal database.
- Real MySQL-backed Vue/Axios order journey: **passed**, 18 pages and 210 viewport checks (320–1920 px). [Journey evidence](journey.json).
- Journey checks: Home reuses existing products/gallery, searches with one real API request and reuses initial products after clearing search; Product options/quantity and add-to-cart through UI; Saved coordinate address, real delivery quote, cash checkout, duplicate-click guard and admin visibility; Real kitchen transitions, ready driver queue, privacy, accept, pickup, on-way, cash and delivered read-only details; Admin completion/payment verification, driver collections and cashier settlement.
- Before/after screenshots are saved for every measured page. Mobile Home/Product/Login visual checks retained identity, colors, RTL and final geometry; branch loading is intentionally more stable. The Şişli reference directory was not edited.
- An additional public photograph was linked only to the synthetic product after all timings. The production Vue Product page successfully loaded the real optimized Laravel URL with unchanged image resource keys, no JS diagnostics and no overflow: [functional evidence](photo-functional.json), [screenshot](photo-functional.png).
- Cleanup: [synthetic schema/file/private manifest removal](cleanup.json); owned audit browsers/servers were stopped. Existing application processes and uploaded originals were retained.
- No API endpoints, database tables, migrations, prices, coupon eligibility or payment behavior were changed. BACKEND → FRONTEND COVERAGE: existing /home featured_products and gallery_preview now feed Home; existing public settings feed Checkout via Pinia; existing branch/product APIs feed the stable Product loading state; existing ProductImageResource/BannerResource/GalleryItemResource URL fields serve eligible local variants without frontend contract changes. Media tests validate dimensions, alpha, fallback, upload resilience, deletion and unchanged resource keys.

## Remaining issues and recommendations before deployment

1. The three uploaded photographs now have WebP variants, but responsive widths/srcset and AVIF are not implemented. Add actual generated widths and a reviewed way to convey dimensions, then compare on staging with real catalog photographs. Do not invent variant URLs without files. Confirm PHP GD/WebP support and public storage/symlink access on deployment; unsupported encoders/disks fall back to originals. Review oriented JPEGs, very large images and remote media storage separately.
   Existing uploaded files are outside source control: generate eligible product derivatives on the deployment filesystem with `php artisan media:optimize --disk=public --directory=products`. This scans files without querying customer data and retains originals; newly uploaded eligible media is optimized through MediaService.
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
