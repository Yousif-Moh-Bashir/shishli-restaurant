# Findings and corrections

## QA-001 — Known test account seeded in production

- Severity: **Medium**. Module: authentication / database seeding. Status: **FIXED**.
- Description: default `DatabaseSeeder` created `test@example.com` through a factory, including the known factory password, regardless of environment. This is a customer account, not an automatic admin grant.
- Reproduction: run default seeding with `APP_ENV=production` on an isolated empty test database; the account existed before the correction.
- Root cause: development fixture creation lacked an environment guard.
- Fix: create it only in `local` and `testing` in `back-end/database/seeders/DatabaseSeeder.php`.
- Test added: production regression in `DemoDataSeederTest`; failure reproduced first, then whole class passed (3 tests / 163 assertions).
- Verification: future production seeding does not create that account. Existing deployed accounts were not inspected or deleted; deployment follow-up is required.

## QA-002 — Customer addresses overflow at 320 px

- Severity: **Low**. Module: customer address page. Status: **FIXED**.
- Reproduction: signed-in customer → `/account/addresses` → 320 px width. The menu link extended about 43 px beyond the viewport.
- Root cause: the title and two navigation buttons remained on a single flex row.
- Fix: scoped wrapping/gap and a full-width title on narrow screens in `AddressesView.vue`.
- Test added: real journey responsive assertion for account addresses; failed before and passed afterwards across all ten widths.
- Verification: complete cash checkout/delivery/settlement journey plus 210 responsive checks passed after correction.

## QA-003 — Regression fixtures do not reflect driver/workday contracts

- Severity: **Low**. Module: test suite. Status: **FIXED**.
- Description: first full run failed 13 cases while the corresponding product contracts had already changed.
- Reproduction: full suite with the current date and driver role enabled.
- Root cause: exact register JSON omitted `is_driver`; order fixtures used a stale day or crossed an overnight boundary; roles/permission counts omitted driver permissions.
- Fix: deterministic clocks and updated exact expected role/permission/public response contracts. Authorization assertions were retained.
- Tests: `RegisterControllerTest`, `InternalDeliveryTest`, `OrderAccessTest`, `RolesAndPermissionsTest`.
- Verification: affected cases passed, 18 tests / 634 assertions; complete suite result recorded in QA_REPORT.

## QA-004 — Browser harness could target default API for mutations

- Severity: **Medium**. Module: QA tooling. Status: **FIXED**.
- Reproduction: override frontend to isolated environment while legacy auth/media or domains script still hardcodes API port 8000.
- Root cause: inconsistent environment configuration in legacy browser scripts.
- Fix: read `DESIGN_API_URL` and reject ports other than the isolated QA port in mutating suites; new journey also guards the frontend port. Fixture-suite unauthenticated probes now default to isolated API.
- Verification: real auth/media and domains suites passed against isolated seeded SQLite; no real upload/business mutation was allowed to continue on the default API.

## QA-005 — Stale browser selectors, cached modules and noninteractive clicks

- Severity: **Low**. Module: QA tooling. Status: **FIXED** (final affected scenario results in QA_REPORT).
- Root cause: old kitchen card class, renamed collection button, custom CMS confirmation replacing native confirmation, cached intercepted imports, and clicks occurring during busy states or inside closed dialogs.
- Fix: current selectors, enabled/visible control waits, custom confirmation handling, disabled network cache, and expected absent-cart 404 handling limited to that exact endpoint.
- Verification: real admin smoke, domains, journey and notification suites passed. Other harness reruns are reported separately rather than counted as application defects.

## QA-006 — Large static assets

- Severity: **Low**. Module: frontend performance. Status: **OPEN**.
- Reproduction: production build emits 783,022-byte logo and 1,644,970-byte reference image.
- Root cause: assets substantially exceed their rendered logo size; reference image supports existing visual backgrounds.
- Fix applied: none in this audit; image quality/responsive variants need separate visual verification.
- Verification: build size is confirmed; real-device slow-network impact NOT TESTED.

Previously corrected immutable order-placement timestamp/workday filtering was revalidated by isolated MySQL tests; it is not counted as a newly discovered audit defect.
