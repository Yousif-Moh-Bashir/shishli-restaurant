# Şişli admin presentation

The legacy `Şişli/dashboard` is the visual reference: Tajawal, RTL, cream panels,
maroon text and outline icons, red primary actions, compact outlined row actions,
subtle borders, status badges, tables, card headings and two-column summaries.
The Vue application and its existing API modules remain the functional authority.
No legacy source, Laravel source, API URLs, permission names or mutation payloads
were changed for this presentation task.

## BACKEND → FRONTEND COVERAGE

| Section | Existing backend features and UI retained | Presentation applied |
| --- | --- | --- |
| Overview | Dashboard aggregates, recent orders, comparisons, date/branch filters and report permissions | Legacy metric cards, outline icons, orders table and slim state/sales bars |
| Reports | Server summaries, all report tabs, inventory filters, pagination, branch drilldown and authenticated CSV export | Icon cards, legacy headings and thin bars; secondary filters expandable |
| Products | CRUD, global status/availability bulk actions, featured/inventory filters, pagination | Image/name table, separate price/category/availability, compact actions |
| Product details | Image upload/delete/reorder/primary image, group attachment/overrides/detachment, branch price/availability/attachment | Product summary with image, facts and badges; icon section headings and nested forms |
| Categories | CRUD, parent selection, category tree and server product counts | Legacy description/count columns, outlined actions, expandable sorting |
| Branches | CRUD, active/order acceptance flags, pickup/delivery support, contacts/location | Icon/name table, service badges, contact details and consistent actions |
| Branch details | Branch products, effective prices/availability, attach/detach, bulk assignment and delivery-zone access | Labeled facts, nested tables/forms and consistent section headings |
| Product options | Group/value CRUD, defaults, required/min/max rules and display order | Icon group table, facts and nested value form |
| Orders | Search/all filters, server counts, pagination, polling, server-permitted confirm/prepare/ready/dispatch/complete/cancel actions | Legacy order cards, status filter buttons, compact main filters and expandable secondary filters |
| Order details | Saved item/pricing/address snapshots, history, cash collection and linked notifications | Legacy detail panels, facts, summaries and timeline; action confirmations preserved |
| Kitchen | Queue, branch/status/type filters, polling, item/options/notes snapshots and permitted operations | Legacy preparation cards, outline actions and responsive queue |
| Payments | List filters, polling, payment/transaction details and permission-bound cash collection | Legacy list table, expandable secondary filters and transaction cards |
| Notifications | List filters, details/content, paginated attempts, retry permissions/confirmation and polling | Legacy table, expandable secondary filters, message/attempt panels |
| Delivery zones | Branch selection, district/radius zones, fees, limits, time estimates, priority, activation and CRUD | Legacy outlined table/actions, responsive forms and delete dialog |
| Offers | Existing browser preview only; promotion/coupon backend integration is still separate work | Existing legacy table/modal retained with shared responsive styling |
| Customers | Existing browser preview only; real customer aggregate reporting remains available under Reports | Existing legacy customer table/history modal retained |
| Settings | Existing browser preview only; this task does not add configuration API integration | Legacy form grid, icon section heading and existing save validation |

Missing presentation discovered: crowded filter grids, mixed payment cards/list
patterns, undecorated detail sections and incomplete styling of nested forms,
images, facts, attempts and transactions. These were implemented in shared
`AdminFilterExtras`, `AdminSectionHeading` and admin CSS, with page-specific
templates where needed. All existing controls remain available. Advanced filters
open when server field errors are supplied; native required validation remains.

No new module integration was requested or claimed. Existing preview disclosures
remain visible. Internal jobs, database identifiers, provider secrets and backend
state-transition implementations are intentionally not made editable: this change
is presentation only and server responses still authorize each operation.

## Verification

- `npm run build` validates the complete Vue bundle.
- `node --experimental-websocket tests/admin-design.browser.mjs` checks real local
  API pages, desktop/tablet/mobile widths, expandable filters, CRUD dialogs and
  native required validation, plus real empty-search responses. It performs no
  business-data writes and revokes its temporary login token afterward.
- The live notification list can be empty; populated notification detail/retry
  scenarios are covered by the existing notification browser fixture suite.
- Existing lifecycle/payment/notification suites cover action permissions,
  confirmations, validation and server error behavior using controlled HTTP
  fixtures. Their results must be distinguished from live API checks.

Final results: build passed. The new live design check passed 20 pages, 60 viewport
checks (1440/768/390), four CRUD dialogs and 11 filter checks, with zero browser
diagnostics or failed HTTP responses. The notification fixture suite passed nine
scenario groups / 15 viewport checks; payments passed 13 / 12; lifecycle passed
12 / 15. Reports (`--browser-only`) passed 30 fixture scenario groups / 45 viewport
checks and refreshed authenticated live report views and a real CSV download.
The browser-only reports command retains earlier HTTP audit entries in its output;
those retained entries are not claimed as rerun in this presentation task.

Non-blocking build warnings: the existing mixed static/dynamic `apiError` import,
and the application bundle slightly exceeds Vite's 500 kB chunk warning threshold.

## Follow-up review of legacy interactions

The remaining operation, cash-collection and notification-retry confirmations now
use the same outline icon headings, cream surfaces, bordered fields and separated
primary/secondary actions. Their eligibility, payloads, confirmation behavior,
duplicate-submit protection and server error handling remain unchanged.

Order cards now expose the existing operational controls directly, matching the
legacy card workflow. `AdminOrderResource.available_actions` and actual permission
names control visibility; `AdminOrderOperations` still reads the latest order
before any POST and refetches the board after a successful operation. List polling
pauses while a card confirmation is open. Cards cannot open an operation during
an in-flight list refresh, and navigation is guarded while an operation is busy.
No backend state transitions or endpoint mappings were duplicated in the board.

BACKEND → FRONTEND COVERAGE supplement: confirm, preparation, ready, dispatch,
completion and cancellation already existed in backend and detail UI; direct card
controls were the missing legacy interaction and are now implemented. Cash
collection and notification retries retain their existing controls, with matching
confirmation presentation. Internal jobs/provider credentials remain unexposed.

Follow-up verification passed: production build, the 20-page live design check
(60 viewports, four CRUD dialogs, 11 filter checks), lifecycle (13 scenario groups,
18 viewport checks including card confirmations), payments (13/12), notifications
(9/15). The new lifecycle card scenario verifies server action visibility, no POST
before confirmation, paused list polling, guarded busy navigation and duplicate
submission prevention. Operational mutations in these suites use HTTP fixtures;
the live design check remains read-only for business data. No browser diagnostics
or asset failures were reported.
