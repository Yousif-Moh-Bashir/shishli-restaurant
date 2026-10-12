# Shishli — QA audit, 2026-10-09

## Outcome

Actual source review, Laravel tests, isolated MySQL concurrency tests, Chrome UI tests, dependency audits and a production frontend build were performed. This is a local verification, not production certification. Existing unrelated working-tree changes were preserved; the original `Şişli` reference was not edited.

Two application defects were corrected: production seeding of a known test account, and a mobile overflow in customer addresses. Test contracts/fixtures and browser harness isolation/selectors were corrected separately. See [BUGS_FOUND](BUGS_FOUND.md).

Finding totals: **6 records, 5 fixed, 1 open (large static assets)**. Of these, 2 are application defects, 3 are QA-tool/fixture defects, and 1 is a performance improvement. One confirmed application security finding (Medium) was fixed; the separate QA isolation finding (Medium) was also fixed. No Critical/High finding was confirmed during this audit; this is not evidence that none exists.

## Verified results

| Check | Result |
|---|---|
| Full Laravel suite | 2,087 discovered: **2,081 passed, 0 failed, 6 skipped**; 12,121 assertions; 1,885.326 seconds |
| Changed role/register/order fixtures | 18 passed, 634 assertions |
| Authentication and customer/admin/driver route boundary matrix | 2 passed, 1,070 assertions |
| Demo seeding, including production-account regression | 3 passed, 163 assertions |
| Isolated MySQL inventory/driver/staff/role/order timestamp checks | 6 passed, 78 assertions |
| Two simultaneous checkouts against a coupon with one use | 1 passed, 13 assertions; one 201 and one 422, one order and one usage |
| Existing polling/access/business-hours/geolocation Node suites | All four passed |
| Real API admin page smoke | 26 pages × 10 widths = 260 checks; 4 dialogs, 16 filters; zero JS diagnostics or unexpected HTTP failures |
| Isolated real cash order journey | Passed; 18 public/account pages and 210 width checks including checkout, driver details and collections |
| Real API CMS/promotions/staff/customer domains | 21 checks passed, zero JS diagnostics |
| Real API authentication/media uploads | 5 checks passed, zero JS diagnostics |
| Driver UI fixtures | Passed: branch/privacy, conflicts, transitions, collections, errors, completed read-only view |
| Notification UI fixtures | 9 scenarios, 15 responsive checks, 4 unauthenticated API denials passed |
| Payment UI fixtures | 13 scenarios, 12 responsive checks, 5 unauthenticated API denials passed; zero diagnostics |
| Supplemental actual API pages | Product, forbidden, tracking, success and admin drivers: 5 pages × 10 widths = 50 passed |
| Journey/report reconciliation | Passed: delivered cash order present in completed cash report; total equals admin snapshot (80.00 SAR) |
| Order lifecycle UI fixtures | 13 scenarios, 18 responsive checks, 4 API access denials passed; zero diagnostics or asset failures |
| `composer audit` | No advisories or abandoned packages reported |
| `npm audit --registry=https://registry.npmjs.org` | Zero reported vulnerabilities |
| `npm run build` | Passed: 340 modules, 8.50 seconds |
| Pint | Passed; formatted changed PHP tests |
| Isolated `config:cache` / `config:clear` | Passed using a separate QA cache path; normal app cache untouched |
| Frontend lint / Playwright | NOT TESTED: no lint script or Playwright package configured; Chrome CDP suites were run instead |

## End-to-end evidence

`front-end/tests/qa-journey.browser.mjs` uses real Vue, Axios and Laravel against a new seeded SQLite file. It selects a product/options/quantity, adds it to the cart, selects saved coordinates, gets a delivery quote, confirms cash checkout with a double-click guard, verifies admin visibility, performs confirmed → preparing → ready, accepts as the branch driver, picks up → goes on the way → collects cash → delivers, verifies completed/paid in admin, and settles the collection with the cashier. Delivered driver details expose no action controls. Evidence: [journey-browser.json](journey-browser.json). A separate real API reconciliation verifies this same order appears in the completed cash report with the exact admin order total: [report-reconciliation.json](report-reconciliation.json).

Responsive widths: **320, 360, 375, 390, 412, 430, 768, 1024, 1366, 1920**. Tests verify overflow and relevant dialogs; they do not constitute human pixel-by-pixel review of every UI state.

## Safety and environment

Browser business mutations ran against `storage/framework/testing/qa-browser.sqlite` through API port 8017 and frontend 5178, using synthetic demo accounts. MySQL tests created separately named databases and removed only those verified test databases. No actual payment charge, SMS, email delivery, GPS tracking, production load test, production migration/reset or customer record deletion was performed.

A legacy media test initially contained a hardcoded default API URL. It was stopped before its upload phase, then changed to require the isolated API and rerun successfully. Initial login/session requests to the local default service cannot be represented as isolated business testing. Mutating browser scripts now have explicit isolation guards.

## Performance and launch assessment

Routes/pages are split into lazy chunks; API list pagination, bounded report exports and polling cancellation/cooldown are covered by source/tests. Limited sequential local measurements after the heavy backend suite ended are in [local-performance.json](local-performance.json): 40 requests, median 603–739 ms; menu maximum sampled latency 896 ms, payload 6,299 bytes. These development-server/SQLite measurements are unsuitable for a production SLA. The build still includes a 783 KB logo and a 1.645 MB reference image; optimize these assets separately with visual verification. Production MySQL EXPLAIN, queue throughput, cold cache and concurrent-load profiling remain NOT TESTED.

Cash ordering and internal delivery passed the isolated workflow. Production launch readiness remains **unverified** until deployment configuration and external services are tested on staging. See [REMAINING_RISKS](REMAINING_RISKS.md).

## BACKEND → FRONTEND COVERAGE

Real API coverage includes catalog/cart/quote/checkout, customer profile/addresses/orders, admin order and kitchen transitions, driver assignment/collection/settlement, CMS/media/promotions/staff forms. Fixture tests cover conflict and transport failures without external side effects. Backend endpoint and business-case completeness is documented in [TEST_COVERAGE](TEST_COVERAGE.md); every combination of every button/form/role is not claimed tested.

## Files changed in this audit

Application: `back-end/database/seeders/DatabaseSeeder.php`, `front-end/src/views/site/AddressesView.vue`. No new tables/endpoints or production data changes were required.

Backend tests: new `AuditAccessBoundaryTest.php` and `CouponCheckoutConcurrencyTest.php`; modified `DemoDataSeederTest.php`, `RolesAndPermissionsTest.php`, `OrderAccessTest.php`, `InternalDeliveryTest.php`, and auth `RegisterControllerTest.php`.

Frontend QA: updated browser client/cache and final-audit/auth-media/domains/lifecycle/payments/notifications scripts; added real journey, supplemental pages, local performance and address diagnostic scripts. Reports/inventory generators and JSON evidence are under `docs/qa`. The working tree includes previous user-requested changes; this list identifies audit scope rather than attributing all Git changes to this audit.
