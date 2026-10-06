# Skeleton loading coverage

Reusable `AppSkeleton` renders table, card, statistics, list, detail, page and inline placeholders using the existing cream palette. Decorative shapes are hidden from screen readers; a polite status announces loading. Reduced-motion preferences disable shimmer. Layouts support RTL and mobile widths.

BACKEND → FRONTEND COVERAGE

- Existing backend reads covered: catalog lists/details and related resources, branch selectors, category choices, order lists/details/tracking, kitchen, payments, notifications, reports, delivery zones, public menu/product, cart, checkout settings/delivery quote, customer addresses/orders/payments and session bootstrap.
- Existing preview customer/offer lists also use skeletons while their local loading state runs; this does not add backend integration to those previews.
- Missing indicators implemented for category/branch choices and record reads before opening catalog, address and delivery-zone editors.
- Initial reads use content-shaped placeholders. Polling/silent refresh keeps previous data visible with a small inline skeleton. Saving, payment submission and business actions retain their disabled controls and operation labels.
- Existing errors, retry controls, empty states, permissions, validation and API contracts are preserved. No backend changes or new endpoints. Internal backend jobs/events require no loading UI.

Verification: production build, `tests/skeleton.browser.mjs` and `tests/skeleton-public.browser.mjs`. Tests delay real API responses by 900 ms and check visible accessible loading shapes, list/detail rendering, responsive widths, dialogs and filters. Public checks also verify reduced motion and removal of placeholders after requests finish. Preview pages are identified separately in reports. The cart's existing missing/converted-cart 404 recovery is accepted explicitly; other HTTP failures fail the checks.

Completed checks: 20 admin pages, 60 responsive checks, 4 dialogs and 11 filter checks; 6 public/account pages and 18 responsive loading checks. Both browser runs completed without console diagnostics or HTTP failures. Production build passed with the existing bundle-size and mixed-import warnings.
