# Cart + Pricing API integration

## Backend audit / actual contract

Inspected routes/api.php; CartController, CartItemController; CreateCartRequest, AddCartItemRequest, UpdateCartItemQuantityRequest, UpdateCartItemOptionsRequest; CartResource, CartItemResource, CartItemOptionResource; Cart, CartItem, CartItemOption and cart schema; all Cart actions (including branch change, clear, quantity/options updates, refresh and totals); CartResolver, CartMutation, CartStateService, CartItemWriter, PricingService, OptionSelectionValidator, OptionSelectionException, inventory availability, branch availability, PromotionEngine presentation; CartStatus, CartIssueCode, InventoryIssueCode; OptionalCartAuthentication; auth login/register actions; CartTest, CartItemTest, CartRefreshTest and PricingServiceTest. Ownership is enforced by CartResolver, not admin permissions or a cart policy.

All paths below are under the configured /api/v1 base URL. Responses use {success,message,data,errors}; each cart mutation returns the full CartResource.

| Method | Path | Body |
|---|---|---|
| POST | /cart | {branch_uuid} |
| GET | /cart | none |
| PATCH | /cart | {branch_uuid} |
| POST | /cart/refresh | {} |
| POST | /cart/items | {product_uuid,quantity,options} |
| PATCH | /cart/items/{item UUID} | {quantity} |
| PATCH | /cart/items/{item UUID}/options | {options} |
| DELETE | /cart/items/{item UUID} | none |
| DELETE | /cart/items | none |

Accept: application/json; JSON request Content-Type handled by Axios. Guests send only X-Cart-Token on cart access/mutations. Authenticated requests use the existing Sanctum Bearer interceptor and X-Cart-UUID to select the loaded owned cart. No cart headers are sent to catalog/auth/admin requests. No numeric cart ID, user ID or money is submitted.

Guest POST always creates a cart, returning its opaque secret once. Database stores SHA-256(secret); guest expires after seven days. Frontend saves only the guest secret under shishli_cart_token, centrally in the existing Pinia cart store. GET restores it without rotating it. Only definitive GET 404 permits one replacement creation; network/500/401 do not remove it. If there is no selected branch, creation waits for selection. Invalid Bearer returns 401 even on these optionally authenticated routes; guest GET without a secret is also 401. Owned carts are invisible to guests and other users. Authenticated POST creates or reuses an active cart for that user and branch. Authenticated GET without a selector is ambiguous (422) when multiple active branch carts exist; frontend resolves that through POST for the selected branch, then uses the returned UUID. No account cart contents or selectors are persisted locally.

Login does not claim, attach or merge a guest cart in backend. Frontend preserves guest secret, loads owned cart and explains that guest contents will return on logout. Register returns a user but no token: cart remains guest. Logout preserves account cart on server and restores the existing guest secret (or creates a guest cart when a branch is selected and none exists). Auth transitions invalidate stale in-flight cart responses. The global token watcher covers Site and Admin transitions.

A cart owns one branch. Its resource branch overrides the frontend preference. Nonempty branch changes are rejected with 422. UI warns and requires explicit confirmation before clearing then PATCHing branch. These are two requests: if PATCH fails after clear, the original branch remains selected and its cart is empty; the confirmation explicitly explains this. Duplicate active owned target cart is also 422 and is displayed, not merged in Vue.

Actual options: [{option_group_uuid,option_value_uuids:[UUID,...]}]. Quantity is integer 1..50, including a merged line; at most 100 configurations. Server validates assignment, availability, required groups, min/max and membership. Same product plus the same sorted selected DB value IDs produces server configuration_hash (SHA-256), merging quantities; changed options can merge existing lines. Frontend computes no signature. Cart options can be reviewed/edited through ProductView using cartItem query parameter and PATCH /options; quantity is edited separately in cart.

PricingService reads branch price_override or product base_price, adds trusted DB option modifiers, then multiplies by quantity using integer minor-unit Money. Server applies its existing promotion engine to resource totals, without new coupons/promotions UI. Resource item pricing: base_price, options_total, unit_price, line_total, discount_total, line_total_after_discount. Summary: items_count, quantity_total, subtotal, discount_total, total. Amounts are decimal strings; shared formatter is display only. Currency setting is fixed SAR in RestaurantSettingDefinition; CartResource itself carries no currency field. It carries no delivery_fee or tax; neither is invented.

GET validates current availability/options but keeps price snapshots. It has no PRICE_CHANGED issue. Explicit POST /refresh reprices valid items and option modifiers, preserving selected-name snapshots and invalid lines. The UI explains that prices may change and displays returned totals. Quantity/options mutations also reprice the changed configuration. No frontend price acceptance workflow is invented.

Cart issue codes: PRODUCT_UNAVAILABLE, PRODUCT_NOT_IN_BRANCH, OPTION_UNAVAILABLE, OPTIONS_CONFIGURATION_CHANGED, BRANCH_NOT_ACCEPTING_ORDERS, PRICE_LIMIT_EXCEEDED. Inventory adds INVENTORY_NOT_CONFIGURED, OUT_OF_STOCK, INSUFFICIENT_STOCK. Branch availability has its own reason in availability.reason. Messages/codes are displayed directly, including unknown future codes. can_checkout is authoritative: any cart/item issue, empty cart or unorderable branch blocks; frontend also blocks while loading/mutating or after errors. No checkout API was integrated.

Backend mutations lock user/cart rows in a DB transaction with three attempts; frontend disallows concurrent mutation submissions. 422 field/general feedback uses the existing normalizer. 409 is shown and cart is reread; 404 mutation errors are shown, without guessing that an item-level 404 invalidates the whole guest secret. Retry GET performs definitive recovery. No success toast before a successful server response.

## Frontend files / UI coverage

New: src/api/cart.api.js; tests/cart.browser.mjs; this report.
Modified: src/stores/cart.js, src/stores/branch.js, src/main.js, src/layouts/SiteLayout.vue; CartItems.vue, CartSummary.vue, BranchSelector.vue, ProductCard.vue, SiteOffers.vue; ProductView.vue, MenuView.vue, CartView.vue; CheckoutView.vue (display-only adaptation to resource item fields); .gitignore (generated browser profile/report).

Header badge remains on the existing store getter but now uses summary.quantity_total (sum of quantities), not row count or local sums. Bootstrap is deduplicated in store, initiated by SiteLayout; consumers do not independently create/load carts. Main observes auth token transitions. Prices/items/summary are server state only. Old shishli.cart.v1 persistence and demo snapshots/hash/local totals were removed; key is discarded without importing demo data. Guest secret and existing shishli.selectedBranch preference remain; unrelated existing auth persistence remains. Demo offer/cart actions cannot add fake identifiers or prices. Nonintegrated offers remain visual, with add disabled; real menu cards call cart API and direct option-validation failures to product configuration.

Missing UI implemented: explicit reprice with explanation, clear confirmation, per-line/cart issues, branch display, blocked checkout state, distinct loading/network/empty states and retry, server summary discount, selected option names/modifiers, options-edit link/form, branch-change confirmation, guest/account-transition notice and persistence warning. Existing colors/components/RTL/responsive structure retained. No backend source modifications. No Addresses, Delivery Zones, Delivery Quote, Checkout, Orders, Payments or Notifications integration.

Internal backend-only features remain hidden: token hashing, numeric IDs, configuration hashes, DB locks, expirations/status edit controls, trusted prices and permissions. Coupon/delivery quote endpoints were found but excluded by requested scope; summary displays existing server discounts without coupon entry. No new notification system: SiteLayout toast is reused.

## Verification

Backend: php artisan test --compact --filter='CartTest|CartItemTest|CartRefreshTest|PricingServiceTest' passed: 97 tests, 738 assertions. Includes guest ownership/token/expiry, real Sanctum optional authentication, same/different configurations, option validation/tampering, branch restrictions, quantity/options merge, remove/clear, availability and snapshot/refresh pricing. This is isolated backend test coverage, not production end-to-end evidence.

Live Laravel was started at the existing configured port. GET /branches returned HTTP 500; Laravel log identifies MySQL connection failure. Therefore live guest add/reload, options, branch change, price-change admin scenario and real login transition cannot be certified in this environment. No backend configuration/data was changed to hide this failure.

Browser test: node --experimental-websocket tests/cart.browser.mjs. Uses existing Chrome/CDP pattern, no dependencies installed. Separates live results from controlled transports. Browser fixtures passed: UUID-only add/options requests, rapid quantity duplicate prevention, network state preservation, explicit server-total refresh and checkout blocking, guest/account transition, and invalid-token recovery. No Runtime/console diagnostics in the exercised scope; responsive widths 390/768/1440 passed. Route smoke checks passed for /, /menu, /product/:uuid, /cart, /login, /register and guest /admin guard. Authenticated admin business workflows and a complete asset-404 audit were not verified in this task. Results are recorded in .cart-check/report.json (ignored). Transport fixtures exercise actual Pinia store and auth transition watcher; they are not a demo fallback in application code. Live mutation test uses current catalog only and does not alter admin prices/availability.

npm run build: successful. Existing ineffective dynamic import warning in admin CatalogEditor/CatalogList remains unrelated to cart; no build errors. Browser console claims are limited to executed pages/tests, not an assertion that every admin workflow was tested.

## BACKEND → FRONTEND COVERAGE

| Backend feature found | Frontend coverage |
|---|---|
| Guest and owned cart creation/retrieval, token/UUID selectors | Existing store + cart API + deduplicated bootstrap |
| Add / quantity / remove / clear | Real menu/product controls, cart controls and confirmation |
| Options replacement and configuration merge | ProductView options editor; server returned full cart wins |
| Branch change on empty carts | Resource-driven branch synchronization; explicit clear/change warning |
| Snapshot prices / explicit refresh | Server money display and update/reprice button |
| Issues / inventory / can_checkout | Item/cart feedback and disabled checkout navigation |
| Login/register/logout ownership | Separate server contexts; guest secret preserved; missing merge documented |
| Existing automatic discounts | Server summary and line discounts displayed |
| Coupon / delivery quote | Discovered, intentionally deferred by module scope |
| Internal security/locking/hash | Backend authority; no user-editable UI |

## NEXT MODULE READINESS

Frontend cart integration is implemented and backend tests pass. Full live acceptance remains blocked by local MySQL connectivity; restore database access and rerun browser live scenarios before accepting production readiness. Guest-to-account merge remains a missing backend capability if business requires continuity across login. No backend merge fix was attempted. Customer Addresses + Delivery Zones + Delivery Quote can be the next scoped module after these verification limits are resolved/accepted. It has not been started.


