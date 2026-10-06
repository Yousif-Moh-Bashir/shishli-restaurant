# Detail page presentation

The detail views use the existing Şişli colors, Tajawal typography, outline icons, cards, tables, and RTL layout. No backend source or legacy source changed.

- Products: a large primary image or existing placeholder, category, price, availability badges, a header edit action, separate information and description cards, and the existing image/options/branch management sections.
- Branches: grouped branch information and delivery links, followed by the existing operations, hours, closures, and product controls.
- Option groups: settings beside the value table on wide screens; stacked cards on smaller screens.
- Orders and kitchen: a clearer order summary, section icons, readable information rows and item spacing.
- Payments and notifications: summary and supporting content in responsive columns, with transaction/attempt cards and the existing paginated records.
- Customer product and order pages: scoped card and option spacing improvements.

## BACKEND → FRONTEND COVERAGE

This is a presentation task over the already integrated APIs. Existing catalog CRUD, image upload/primary/reorder/delete, option values and assignments, branch product configuration, operating hours/closures/manual flags, order actions/history, payment collection/transactions and notification retry/attempts remain wired to their original APIs and permission checks. No missing backend operation was identified or implemented in this visual scope. No new server state, endpoint, permission or business rule was introduced. Internal backend jobs and providers remain outside the presentation scope.

## Verification

Production build passes. Read-only browser checks passed on 20 admin pages at three widths (60 responsive checks), four dialogs and eleven filter checks, with no browser diagnostics or HTTP errors. Detail routes checked with real data: product, branch, option group, order, kitchen order and payment. No notification record was available in the list for a notification detail runtime check. Forms were opened without saving business changes; this run does not validate every domain mutation. Customer presentation changes are covered by the build; the admin browser run does not cover customer checkout/payment interactions. Existing bundle-size and mixed-import build warnings remain.
