# Test coverage and reproducibility

No code-coverage instrumentation was enabled, so no percentage or exhaustive endpoint branch claim is made. [AUDIT_INVENTORY](AUDIT_INVENTORY.md) records 222 runtime API routes, authorization middleware, reflected controllers/FormRequests and candidate tests. Candidate matches are source discovery, not proof that all branches execute.

Final full Laravel run: **2,087 tests, 2,081 passed, 0 failed, 6 skipped, 12,121 assertions**, 1,885.326 seconds. The new coupon concurrency file was added after this run began discovering tests and was verified separately on MySQL (1 passed / 13 assertions); it is not included in that full-run count. Initial baseline: 2,083 tests, 2,064 passed, 13 failed, 6 skipped; failures were stale fixtures/contracts corrected and rerun.

## Backend domains

| Domain | Existing suites exercised in full Laravel run; additional evidence |
|---|---|
| Auth/users/roles | Auth controller tests, RolesAndPermissions, AdminRoleManagement, CustomerDomainSecurity, AdminIntegrationSecurity; new AuditAccessBoundary matrix and production seed regression |
| Catalog/branches/options/uploads/CMS | Menu/Product/Branch lifecycle and migration suites, CMS pages/media/security, inventory availability; real image upload/display/delete and CMS form saves |
| Cart/checkout/quotes | Cart/CartItem/CartRefresh, Checkout, CustomerAddress, DeliveryQuote/CartDeliveryQuote, pricing/promotion revalidation and transactional order tests; real saved-coordinate cash checkout |
| Orders/kitchen/workday | OrderAccess, OrderLifecycle/Validation/Transaction, Cancellation, tracking/timeline/estimates/performance/security; real confirm/preparing/ready and admin visibility |
| Delivery drivers | InternalDelivery and DriverAcceptanceConcurrency; real accept/pickup/on-way/deliver and fixture 401/403/404/409/422/500 cases, readonly completion |
| Payments/webhooks/collections | CashPayment, AdminPayment, PaymentPolicy, InitiatePayment, PaymentWebhook; real driver collection and cashier settlement |
| Promotions | Promotions, PromotionRevalidation; new MySQL coupon usage-limit race |
| Notifications | Notifications/NotificationEvents/Isolation/Templates; browser failure/retry state tests, no actual external send |
| Reports/settings/schedules | Reports, RestaurantSettings, BranchSchedule/AdminOperations; real responsive report pages and filters; business-hour state/save unit suites |

New files: `tests/Feature/AuditAccessBoundaryTest.php`, `tests/Feature/CouponCheckoutConcurrencyTest.php`; regression added to `DemoDataSeederTest.php`. Fixture/contract changes are detailed in BUGS_FOUND.

## MySQL concurrency

Run with `INVENTORY_MYSQL_CONCURRENCY=1` and `DRIVER_MYSQL_CONCURRENCY=1`, using scoped, fresh randomly named test databases, never the restaurant database. Driver race: two workers compete for one order and a driver cannot accept two active orders. Coupon race: two workers on different branches/carts compete for the last coupon use; expect 201/422 and one usage/order. Separate timestamp test verifies updates preserve `placed_at`. Staff/role checks verify locking/MySQL constraints.

The normal SQLite suite skips environment-gated MySQL tests; their separately reported successful runs must not be mistaken for SQLite row-lock verification.

## Browser testing

- Real API: `qa-journey.browser.mjs`, `final-audit.browser.mjs`, `final-domains.browser.mjs`, `final-auth-media.browser.mjs` against API 8017 / frontend 5178 and synthetic SQLite database.
- Supplemental real API: `qa-remaining-pages.browser.mjs`, five additional page states and 50 responsive checks; evidence in `docs/qa/remaining-pages-browser.json`.
- Real report reconciliation: `qa-report-reconciliation.mjs` locates the completed cash journey order in the filtered orders report and compares its total with the admin snapshot.
- Fixture UI: `drivers.browser.mjs`, `lifecycle.browser.mjs`, `payments.browser.mjs`, `notifications.browser.mjs` intercept HTTP; frontend components/router/Pinia/Axios remain real. Their unauthenticated API denial checks are separate from positive fixture business behavior.
- Node logic: `order-polling.mjs`, `order-access.mjs`, `business-hours-save.mjs`, `checkout-location.mjs`.
- Ten-width actual page checks: 320 / 360 / 375 / 390 / 412 / 430 / 768 / 1024 / 1366 / 1920. Admin dialogs were inspected at four forms; fixture driver/admin flows also tested narrow screens.

Artifacts: `docs/qa/journey-browser.json`, frontend `.reports-check/final-smoke/report.json`, `.reports-check/final-domains/report.json`, `.reports-check/final-auth-media/report.json`, and suite-specific browser reports. Failed intermediate runs remain evidence of reproduction; use the final successful run, not an older failure, when assessing the final state.

## Commands

Backend: `php artisan test`, `php artisan route:list --path=api --json`, `composer audit --format=json`, `php vendor/bin/pint --dirty --format agent`. Configuration caching used `APP_CONFIG_CACHE=storage/framework/testing/qa-config.php` and was cleared immediately. Frontend: `npm run build`, HTTPS-registry `npm audit`, Node logic scripts, Chrome CDP scripts. No lint or Playwright package is configured.

## NOT TESTED

Every form field/button combination, all real device/browser engines, production high-load and queue throughput, deployment HTTPS/debug/CORS/secrets/headers, real email/SMS/online payments/refunds, live GPS, missing OTP/recovery workflows and exact full branch coverage per route. See REMAINING_RISKS for reasons.
