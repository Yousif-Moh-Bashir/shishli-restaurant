# Admin API Integration & Operations

Base: `/api/v1/admin`. JSON requests use `Accept: application/json`, `Content-Type: application/json`, and `Authorization: Bearer <Sanctum token>`. Multipart uploads retain their existing contracts. All admin routes require an active authenticated identity and the stated backend permission. Customer-only identities are rejected, even if accidentally assigned an administrative direct permission. Staff roles are `super_admin`, `manager`, `cashier`, `kitchen`; existing non-customer identities with direct permissions remain supported.

Public entity identifiers are UUIDs. Spatie's fixed roles use their **names** as public identifiers, for example `cashier`; numeric role/database IDs are never required. Permissions use their existing names. No second login, custom role CRUD, generic bulk engine, UI, or new reporting engine is introduced.

## Login and bootstrap

1. `POST /api/v1/login` with `{"email":"staff@example.com","password":"..."}` or `{"phone":"+249912345678","password":"..."}`. Use exactly one identity. Existing login throttling applies.
2. Store the returned Sanctum token and call `GET /admin/bootstrap`.
3. Build navigation from `navigation`, authorize UI controls using `permissions`, and select the first authorized screen. Dashboard may be absent.
4. Reload bootstrap after role/permission changes. Backend authorization applies to every operation independently.

`GET /me`, `/navigation`, `/bootstrap` need administrative access, without requiring an unrelated screen permission. `/me` returns the profile below. `/navigation` returns just the navigation array. Bootstrap is lightweight:

```json
{
  "success": true,
  "message": "Success",
  "data": {
    "admin": {
      "id": "UUID", "name": "Staff", "email": null, "phone": "+249912345678",
      "is_active": true, "roles": ["cashier"],
      "permissions": ["orders.view", "orders.confirm"],
      "capabilities": {"can_view_dashboard": false, "can_manage_orders": true, "can_manage_products": false}
    },
    "roles": ["cashier"],
    "permissions": ["orders.view", "orders.confirm"],
    "navigation": [{"key":"orders","label":"الطلبات","route":"/orders","permission":"orders.view"}],
    "restaurant": {"name":"...","currency":"SAR","timezone":"Asia/Riyadh"},
    "features": {
      "inventory_enabled":true, "scheduled_orders_enabled":false,
      "delivery_enabled":true, "pickup_enabled":true, "cms_enabled":true,
      "contact_messages_enabled":false
    }
  },
  "errors": null
}
```

Illustrative permissions above are abbreviated; the response contains the identity's actual complete permission set. Navigation uses permissions rather than role names. Reports appear when any granular `reports.*.view` permission exists. Scheduled-order/delivery/pickup flags come from current restaurant settings. Inventory and CMS flags reflect installed modules. No orders, products, heavy reports, private settings, hashes, or credentials are included.

## Responses, pagination, query rules, errors

Detail/write responses retain `{success,message,data,errors}`. Creates return 201; notification retry returns 202. Large lists retain Laravel resource pagination:

```json
{"data":[],"links":{"first":"...","last":"...","prev":null,"next":null},"meta":{"current_page":1,"per_page":20,"total":0,"last_page":1},"success":true,"message":"Success","errors":null}
```

Use `search`, `page >= 1`, `per_page = 1..100`, `sort`, `direction=asc|desc` where listed below. Orders/payments default to 15, other primary admin lists to 20. Kitchen's admin alias defaults to 20; the existing `/api/v1/kitchen/orders` retains 25. Each sort is a server-owned whitelist; invalid sorting returns 422, never raw SQL. Legacy aliases and product sorts remain supported. Boolean query values should be `0` or `1`. Searches are grouped within the remaining filters; searchable columns are explicitly defined per screen. Orders/staff/customers/common lists escape LIKE wildcards literally. Product search preserves its existing module contract.

Dates are `YYYY-MM-DD`; `date_to` must not precede `date_from`. Admin order dates and today's counters use the restaurant timezone with UTC half-open database bounds. Other modules retain their established date semantics. Reports use business dates, have a configured maximum range (366 days), and reject client timezone overrides.

Errors retain field mappings: `{"success":false,"message":"...","errors":{"phone":["..."]}}`. Statuses: 401 unauthenticated/disabled, 403 forbidden, 404 unknown UUID or non-staff UUID, 422 invalid input/business rules, 409 stale operation conflict, 429 throttled, 500 unexpected failure. Domain codes remain in their existing fields: payment errors in `message` and `errors.payment`, stock and availability errors as objects containing `code`, invalid order transition as `errors.code=["INVALID_ORDER_TRANSITION"]`. Do not derive business decisions from translated messages.

Every response, including validation/authentication/unknown-route errors, carries `X-Request-ID`. A valid client UUID is reused; other values are replaced. Exception log context contains `request_id`, `user_uuid`, and route. Request bodies and secrets are not added to log context.

## Screen map and list contracts

All paths in the tables below are relative to `/api/v1/admin`. GET bodies are empty. List responses are paginated resource arrays; detail responses are one resource. The shared errors above apply to every screen.

| Screen | GET endpoint(s) | Permission | Filters/search | Allowed sorts |
| --- | --- | --- | --- | --- |
| Dashboard | `/dashboard` | `dashboard.view` | Report dates, `branch_uuid`, `order_type`, `order_status`, `payment_method` | Existing report contract |
| Orders | `/orders`, `/orders/counts`, `/orders/{order}`, `/orders/{order}/history` | `orders.view` | `search` number/name/phone, `order_number`, `status`, `type`, `payment_status`, `payment_method`, `branch_uuid` (legacy `branch`), `customer_uuid`, dates | `placed_at`, `created_at`, `order_number`, `status`, `total` |
| Kitchen | `/kitchen/orders` | `orders.view` | `status=confirmed|preparing|ready`, `type`, legacy `branch`; FIFO by placement | Fixed FIFO |
| Products | `/products`, `/products/{product}` | `products.view` | name/SKU/descriptions search, `category_uuid` (legacy `category` UUID/slug), `branch_uuid`, `active`, `available`, `featured`, `tracks_inventory`; legacy `is_*` | `name`, `base_price`, `created_at`, `updated_at`, `sku`, `sort_order`; legacy `default`, `price_asc`, `price_desc`, `latest` |
| Categories | `/categories`, `/categories/tree`, `/categories/{category}` | `categories.view` | name/slug search, `active`/`is_active` | `name`, `sort_order`, `created_at` |
| Options | `/option-groups`, `/option-groups/{optionGroup}` | `options.view` | name/slug search, `type`, `active`/`is_active` | `name`, `sort_order`, `created_at` |
| Branches | `/branches`, `/branches/{branch}` | `branches.view` | name/address/phone search, `active`/`is_active` | `name`, `sort_order`, `created_at` |
| Branch Products | `/branches/{branch}/products` | `branches.view` | product search, `category_uuid`/legacy `category`, `available`/`is_available` | `product_name`, `price_override`, `is_available`, `created_at`, `updated_at` |
| Inventory | `/inventory`, `/inventory/{inventory}`, `/inventory/movements`, `/inventory/{inventory}/movements` | `inventory.view` or `inventory.movements.view` for movement lists | Existing search, branch/product/category UUIDs, tracking, low/out-of-stock; movement type/reference/date filters | `product_name`, `available`, `on_hand`, `updated_at` (inventory); existing movement order |
| Customers | `/customers`, `/customers/{customer}`, `/customers/{customer}/orders`, `/customers/{customer}/addresses` | `customers.view` | name/phone/email search, exact phone/email, `active`/`is_active`, `has_orders`, dates (legacy `registered_from/to`) | `created_at`, `name`, `orders_count`, `total_spent`, `last_order_at` |
| Payments | `/payments`, `/payments/{payment}`, `/payments/{payment}/transactions` | `payments.view` | search order number/provider reference, `status`, `method`, `provider`, exact `order_number`, dates | `created_at`, `amount`, `status`, `method` |
| Promotions | `/promotions`, `/promotions/{promotion}` | `promotions.view` | name/description search, `active`/`is_active`, `status=active|future|expired|inactive`, `type`, `discount_type`, `branch_uuid`, overlapping date range | `name`, `priority`, `created_at`, `starts_at`, `ends_at`, `used_count` |
| Coupons | `/promotions/{promotion}/coupons` | `coupons.view` | code search, `active`/`is_active` | `code`, `created_at`, `starts_at`, `ends_at`, `used_count` |
| Notifications | `/notifications`, `/notifications/{notification}`, `/notifications/{notification}/attempts` | `notifications.view` | recipient `search`/legacy `recipient`, status/channel/type/provider/reference UUID/dates | `created_at`, `status`, `channel`, `type` |
| CMS Pages | `/pages`, `/pages/{page}`, `/pages/{page}/sections` | `cms.pages.view` | key/title search | `key`, `title`, `created_at`, `updated_at` |
| Banners | `/banners`, `/banners/{banner}` | `cms.banners.view` | title/subtitle search, `active`/`is_active` | `sort_order`, `title`, `created_at`, `updated_at` |
| Gallery | `/gallery`, `/gallery/{galleryItem}` | `cms.gallery.view` | title/description search, `active`/`is_active` | `sort_order`, `title`, `created_at`, `updated_at` |
| Contact Messages | None installed | None | Disabled feature; no fabricated endpoint or navigation item | — |
| Settings | `/settings`, `/settings/groups`, `/settings/audit` | `settings.view`; audit `settings.audit.view` | Audit key/user UUID/dates/paging | Existing immutable audit order |
| Business Hours | `/branches/{branch}/business-hours`, `/branches/{branch}/closures` | `branches.hours.view`, `branches.closures.view` | Selected branch | Existing schedule order |
| Reports | `/reports/*`, `/reports/inventory` | Granular report permission; inventory `inventory.view` | Report date/branch/type/status/payment filters, `group_by`, `sort_by`, paging | Existing report whitelist |
| Staff | `/staff`, `/staff/{user}` | `staff.view` | name/phone/email search, role, `active`/`is_active` | `name`, `created_at`, `updated_at`, `is_active` |
| Roles / Permissions | `/roles`, `/permissions` | `roles.view` | Fixed small complete catalogs | Fixed name order |
| Activity Log | `/activity-logs` | `activity_logs.view` | action/description/subject UUID `search`, `user_uuid`, `action`, `subject_type`, business dates, paging | `created_at` |

### Screen response details

Dashboard retains the existing aggregate period, summary, order statuses/types, comparison, and definitions. Inventory widgets require `inventory.view`; sales and average-order-value widgets require `reports.sales.view`; collected revenue requires `reports.payments.view`. Counts remain operational. The implementation does not rebuild analytics.

Order details reuse immutable customer, item/option, promotion, address, payment, branch and timeline snapshots, plus `latest_payment` and `status_history`. List rows omit heavy item/image/history lists. `available_actions` contains strings such as `confirm`, `start_preparing`, `mark_ready`, `dispatch`, `complete`, `cancel`, `collect_cash`. They are derived from `OrderStateMachine`, `OrderOperationAuthorization`, `OrderPaymentPolicy`, actual actor permissions and order type. Unpaid online orders cannot be confirmed; a cashier cannot complete delivery. Cash collection requires an unpaid non-cancelled cash order and a matching pending provider-free cash payment with the same amount/currency, without an already paid payment. Commands revalidate all rules under their existing locks; actions are UI guidance, not authorization or a concurrency guarantee.

Kitchen-only users receive kitchen-safe order resources for admin list/detail/status-operation responses: no pricing, contact information, address, payment details, or financial history. Kitchen queue reuses the existing controller. Counts return all status keys and `completed_today`, `cancelled_today`; today's counters use event timestamps rather than placement date. Supplied filters scope counters too.

Products list loads category and primary image only. Detail adds all images/options, branch UUID/name/price override/availability, and inventory summaries (`on_hand`, `reserved`, `available`, tracking) through eager relationships when the actor has `inventory.view`. Categories include product counts; the small tree is assembled in one query. Branch detail includes business hours, availability from `BranchAvailabilityService`, product and delivery-zone counts. Customer lists reuse SQL aggregates; profile recent orders stay capped at five, with separately paginated history/addresses. Promotion rows include target summaries and usage counts without all usages. Payment resources retain secret redaction; provider reference can be searched without exposing it.

Settings keeps the established flat `/settings` response for compatibility; `/settings/groups` returns `general`, `contact`, `branding`, `orders`, `pickup`, `delivery` arrays. Both use the authoritative setting registry and existing resource. Notification lists retain masked recipients; detail uses the module's existing restricted resource. CMS images/content/publication/cache contracts remain in [CMS_API.md](../CMS_API.md).

## Existing write endpoints and payloads

Update methods below accept the existing module fields, including partial PATCH requests. New admin integration calls existing Actions/Services; it does not directly assign order state, stock balances, or payment ledger status.

| Screen | Method/path | Permission | Request → response / relevant business errors |
| --- | --- | --- | --- |
| Order lifecycle | POST `/orders/{order}/confirm`, `/start-preparing`, `/mark-ready`, `/dispatch`, `/complete` | `orders.confirm`, `orders.start_preparing`, `orders.mark_ready`, `orders.dispatch`, `orders.complete` respectively | Optional `note`, existing operation fields → order snapshot/actions; invalid transition, payment required, stock/branch availability, stale state |
| Cancellation | POST `/orders/{order}/cancel` | `orders.cancel` | `{"reason":"..."}` → cancelled snapshot/history; invalid state/conflict |
| Cash collection | POST `/orders/{order}/payments/cash/collect` | `payments.collect_cash` | Empty body → payment; existing idempotent replay and payment-method/state/amount rules |
| Products | POST `/products`; PUT/PATCH `/products/{product}`; DELETE `/products/{product}` | `products.create/update/delete` | Category UUID, name, base price, optional descriptions/SKU/slug/flags/rank/preparation time → product; invalid category/duplicate SKU/slug |
| Product images | POST `/products/{product}/images`; PATCH `/images/reorder`, `/images/{image}/primary`; DELETE `/images/{image}` | `products.update` | Existing multipart image, ordering or selected primary image → image/module response; upload/scope/primary-image rules |
| Categories | POST `/categories`; PUT/PATCH/DELETE `/categories/{category}` | `categories.create/update/delete` | Name, optional parent UUID/slug/description/image/rank/active → category; invalid parent/cycle or existing dependency rules |
| Options | POST `/option-groups`; PUT/PATCH/DELETE `/option-groups/{optionGroup}`; POST `/values`, PATCH/DELETE `/values/{optionValue}` | `options.create/update/delete` | Existing selection type/min/max/required and value pricing → resource; invalid selection bounds/nested scope |
| Product option assignments | POST/PATCH/DELETE `/products/{product}/option-groups[/{optionGroup}]` | `products.update` | Existing group UUID and optional overrides → assignment; invalid group/selection bounds |
| Branches | POST `/branches`; PUT/PATCH/DELETE `/branches/{branch}` | `branches.create/update/delete` | Name, contact/address/location, pickup/delivery/active/order flags/rank → branch; field validation |
| Branch products | POST `/branches/{branch}/products`, POST `/bulk`, PATCH/DELETE `/{product}` | `branches.products.manage` | Product UUID, optional override/availability; existing bounded bulk `products` assignments → assignment(s); missing/out-of-scope product |
| Delivery zones | POST `/branches/{branch}/delivery-zones`; PATCH/DELETE `/{deliveryZone}` | `delivery_zones.create/update/delete` | Existing zone/geography/fee/minimum/active fields → zone; invalid coverage/fees/scope |
| Inventory | POST `/inventory`; PATCH `/inventory/{inventory}`; POST `/{inventory}/receive`, `/adjust`, `/waste` | `inventory.create`, `inventory.adjust`, `inventory.receive`, `inventory.adjust`, `inventory.waste` | Existing branch/product UUID, initial stock/config or quantity/reason/reference UUID → inventory; stock limits, tracking and idempotency rules |
| Returned stock | POST `/orders/{order}/stock/return` | `inventory.adjust` | Reason → success; existing consumed/cancelled/duplicate-return rules |
| Customers | POST `/customers/{customer}/activate` or `/deactivate` | `customers.activate/deactivate` | Empty body → profile; customer scope and token revocation |
| Promotions | POST `/promotions`; PATCH/DELETE `/promotions/{promotion}` | `promotions.create/update/delete` | Existing type/application/discount/schedule/target UUID/usage-limit fields → promotion; percentage/price/date/target rules |
| Coupons | POST `/promotions/{promotion}/coupons`; PATCH/DELETE `/{coupon}` | `coupons.create/update/delete` | Code, active, schedule and usage limits → coupon; promotion must be coupon type, unique code, valid dates |
| Notifications | POST `/notifications/{notification}/retry` | `notifications.retry` | Empty body → queued notification (202); retryable status and retry limits |
| CMS Pages | PATCH `/pages/{page}` and `/pages/{page}/sections/{section}` | `cms.pages.update` | Existing text/SEO/status/date/section content/media → resource; immutable page key, registry type/schema, upload/URL rules |
| Banners / Gallery | POST, PATCH UUID, DELETE UUID, PATCH `/reorder` under `/banners` or `/gallery` | `cms.<section>.create/update/delete` | Existing multipart images and safe text/schedule/ordering fields → resource; see CMS contract |
| Settings | PATCH `/settings` | `settings.update` | `{"settings":{"orders.enabled":true}}` → success; registry key/type/currency rules, existing audit |
| Business hours | PUT `/branches/{branch}/business-hours` | `branches.hours.update` | `{"hours":[{"day_of_week":0,"opens_at":"10:00","closes_at":"22:00"}]}` → weekly schedule; overlaps, time format and overnight rules |
| Closures | POST `/branches/{branch}/closures`; PATCH/DELETE `/{closure}` | `branches.closures.create/update/delete` | Existing interval/reason fields → closure; valid interval and scope |

## Staff management

| Method/path | Permission | Payload |
| --- | --- | --- |
| GET `/staff`, `/staff/{user}` | `staff.view` | List query or UUID |
| POST `/staff` | `staff.create` | Required `name`, E.164 `phone`, `password`, `roles`; optional nullable `email` |
| PATCH `/staff/{user}` | `staff.update` | Any of name/phone/email/password/roles |
| POST `/staff/{user}/activate` | `staff.activate` | Empty |
| POST `/staff/{user}/deactivate` | `staff.deactivate` | Empty |

```json
{"name":"Kitchen Staff","phone":"+249912345678","email":null,"password":"ChooseStrong123","roles":["kitchen"]}
```

Passwords are 12–72 characters with mixed case and numbers, hashed through Laravel's existing cast. Phones/emails must be unique. Only the four fixed staff roles are assignable; customer-only accounts never appear in this list. Extra identity/permission/active fields are rejected rather than mass assigned. Responses use the public profile shape, with UUID and actual permissions, never hashes/tokens.

Only a super_admin may create/manage a super_admin. Other actors must possess every permission held by the target and every assigned role, preventing password-based takeover or role escalation. Self-deactivation is refused. Role changes that would remove the last active super_admin are refused (`LAST_SUPER_ADMIN`); inactive super_admins do not satisfy this guard. Mutations lock a shared super-admin role row before target users, so simultaneous demotions cannot bypass the check.

Deactivate sets `is_active=false`, deletes Sanctum tokens, retains user/history and logs the operation. Activate does not restore deleted tokens: authenticate again. Password and role changes also revoke tokens. Audit failure rolls back staff mutations. Staff writes and product bulk operations share a 30/minute per-user limiter. Invalid requests count toward it.

## Roles / permissions

Permission names must match the registered web-guard names exactly, including letter case. This is enforced independently of MySQL's case-insensitive collation; mismatched names return validation status 422.

GET `/roles` → `[{"id":"cashier","name":"cashier","permissions":["orders.view", "..."]}, ...]`. GET `/permissions` → prefix-grouped permission names, for example `{"orders":["orders.cancel", "orders.complete", "..."],"staff":["staff.activate", "..."]}`. Both require `roles.view`.

PUT `/roles/{role}/permissions` requires **both** an actual super_admin role and `roles.update_permissions`. Payload: `{"permissions":["orders.view","orders.start_preparing","orders.mark_ready"]}`. The five role names are fixed. Unknown/duplicate/non-web permissions are rejected. Super-admin permissions cannot be edited; customer permissions must remain empty (`PROTECTED_SYSTEM_ROLE`). Operational role changes are transactional, audited, and invalidate Spatie's cache. The `permissions_customized` marker preserves these operational edits across later seeding; super_admin/customer protected baselines remain authoritative. Role updates are limited to 20/minute per user.

New permissions: `staff.view`, `staff.create`, `staff.update`, `staff.activate`, `staff.deactivate`, `roles.view`, `roles.update_permissions`, `activity_logs.view` (92 total). Super-admin gets all; manager gains staff operations and role reading, without role-permission writes/activity-log reading, while retaining the established operational/report policy. Cashier/kitchen/customer baseline permissions are unchanged.

## Activity log and bulk operations

GET `/activity-logs` requires `activity_logs.view`; filters are actor UUID, exact action, subject type and date interval. Response rows:

```json
{"id":"UUID","actor":{"id":"USER_UUID","name":"Staff"},"action":"staff.update","subject":{"type":"user","id":"USER_UUID"},"description":null,"old_values":{"roles":["cashier"]},"new_values":{"roles":["kitchen"],"password_changed":true},"created_at":"2026-10-03T12:00:00+00:00"}
```

Actor may be null after account deletion. Role subjects use `type=role`, a nullable subject UUID, and the fixed role name in approved old/new fields. Activity records are immutable. No GET is logged. Staff/role/bulk audits are in the same database transaction as changes. Cancellation, manual cash collection and promotion changes record a high-level success event via middleware. These middleware audits run after existing domain transactions; an audit-storage outage is reported without returning a false failure for an already committed domain operation. Idempotent cash replays with an unchanged order snapshot do not duplicate the activity event or existing payment ledger.

Settings retain `SettingChangeLog`; inventory retains `StockMovement`; order transitions retain status history; payment financial records retain their ledger. No duplicate generic audit replaces those domain records. Activity snapshots whitelist public name/status/flags, roles/permission names, subject/product UUIDs and `password_changed`. Raw passwords/hashes, tokens, API keys, provider/card/payment secrets, notification credentials, nested arbitrary objects, and raw IP addresses are excluded. `ip_hash` is nullable and currently not collected.

POST `/products/bulk/status` (`products.update`): `{"product_uuids":["UUID","UUID"],"is_active":false}`. POST `/products/bulk/availability`: same UUID array with `is_available`. Both return `{"updated_count":2}` in the success envelope. Arrays require 1–100 distinct existing non-deleted UUIDs; missing UUIDs or unknown fields fail the entire request. Products lock in ID order and reuse `UpdateProductAction` in a single transaction with the activity log. No partial success, generic model/action, bulk delete, or bulk order lifecycle operation is supported.

## Reports, reuse and verification

The full suite passed 2,005 tests with 10,353 assertions and no failures, errors or skips, including enabled MySQL concurrency checks. After the final exact-case validation change, all 14 role-management tests passed (58 assertions), including the new isolated MySQL regression. The resulting suite contains 2,006 tests; a second complete run after this final change was not performed. Additional evidence: `storage/logs/admin-role-final-phpunit.xml`.

Existing `/reports/orders`, `/sales/trend`, `/branches`, `/products`, `/categories`, `/payments`, `/promotions`, `/coupons`, `/customers`, `/delivery`, `/order-statuses`, `/cancellations` remain unchanged. Permission mapping is the corresponding granular `reports.<domain>.view`; categories use products, coupons use promotions, status/cancellation use orders. Existing CSV `/export` endpoints for orders, sales/trend, products, payments, customers, promotions additionally require `reports.export` and their existing 5/minute limit. Report payloads retain the existing success wrapper around report `data/meta/period/summary`; this established analytics contract is not replaced by a new resource paginator.

132 previous admin routes are reused and 19 added, for 151 total. Added: me/bootstrap/navigation (3), staff (6), roles/permissions/role-permission update (3), activity (1), order counts (1), category tree (1), explicit product bulk (2), kitchen alias (1), grouped settings (1).

Focused tests cover bootstrap/privacy/navigation, role×screen authorization, staff hashing/login/activation/revocation/escalation, protected roles, last active super-admin, immutable/safe audit, bulk atomicity and injection rejection, filters/sorts/paging, kitchen privacy, dashboard finance authorization and stable list query counts. The opt-in existing MySQL suite flag also runs a staff concurrency test in a newly created isolated temporary database; it does not mutate the application database. Test evidence is in `storage/logs/admin-targeted-phpunit.xml` and the final `storage/logs/admin-integration-phpunit.xml` after verification.

Database changes: `admin_activity_logs`, nullable user email for phone-only staff, and operational-role permission customization marker. No fictitious staff/demo identities are seeded. Existing business records remain intact.

## File manifest for this integration

These counts refer to the admin integration phase, independent of earlier uncommitted module work. New files: 45. Existing files changed for this integration: 34. Paths are relative to `back-end`.

New:

```text
app/Services/Admin/AdminAccessService.php
app/Services/Admin/AdminActivityService.php
app/Services/Admin/AdminBootstrapService.php
app/Services/Admin/AdminCustomerFilters.php
app/Services/Admin/AdminListQuery.php
app/Services/Admin/AdminNavigationService.php
app/Services/Admin/AdminOrderActions.php
app/Services/Admin/AdminOrderFilters.php
app/Services/Admin/AdminOrderQuery.php
app/Services/Admin/AdminProductFilters.php
app/Services/Admin/CategoryTreeService.php
app/Services/Admin/RolePermissionService.php
app/Services/Admin/StaffService.php
app/Http/Controllers/Api/V1/Admin/ActivityLogController.php
app/Http/Controllers/Api/V1/Admin/AdminSessionController.php
app/Http/Controllers/Api/V1/Admin/RoleController.php
app/Http/Controllers/Api/V1/Admin/StaffController.php
app/Http/Requests/Api/V1/Admin/AdminListRequest.php
app/Http/Requests/Api/V1/Admin/AdminOrdersRequest.php
app/Http/Requests/Api/V1/Admin/BulkProductStatusRequest.php
app/Http/Requests/Api/V1/Admin/PromotionFilterRequest.php
app/Http/Requests/Api/V1/Admin/RolePermissionsRequest.php
app/Http/Requests/Api/V1/Admin/StaffRequest.php
app/Http/Resources/Api/V1/AdminActivityLogResource.php
app/Http/Resources/Api/V1/AdminBranchResource.php
app/Http/Resources/Api/V1/AdminOrderResource.php
app/Http/Resources/Api/V1/AdminProfileResource.php
app/Http/Middleware/EnsureAdminAccess.php
app/Http/Middleware/RecordAdminActivity.php
app/Http/Middleware/RequestId.php
app/Actions/Products/BulkProductUpdateAction.php
app/Models/AdminActivityLog.php
database/factories/AdminActivityLogFactory.php
database/migrations/2026_10_03_113819_create_admin_activity_logs_table.php
database/migrations/2026_10_03_114343_make_staff_email_optional_on_users.php
database/migrations/2026_10_03_121103_add_permission_customization_to_roles.php
tests/Feature/AdminBootstrapTest.php
tests/Feature/AdminIntegrationSecurityTest.php
tests/Feature/AdminListIntegrationTest.php
tests/Feature/AdminOperationsTest.php
tests/Feature/AdminRoleManagementTest.php
tests/Feature/AdminRoleMysqlValidationTest.php
tests/Feature/AdminStaffConcurrencyTest.php
tests/Feature/AdminStaffTest.php
docs/admin-api.md
```

Modified:

```text
routes/api.php
bootstrap/app.php
config/rate_limiting.php
app/Providers/AppServiceProvider.php
database/seeders/RolePermissionSeeder.php
app/Services/Reports/DashboardService.php
app/Services/OrderStateMachine.php
app/Actions/Products/ListAdminProductsAction.php
app/Actions/Branches/ListBranchProductsAction.php
app/Http/Resources/Api/V1/ProductResource.php
app/Http/Requests/Api/V1/Admin/ListProductsRequest.php
app/Http/Requests/Api/V1/Admin/ListCustomersRequest.php
app/Http/Requests/Api/V1/Admin/ListOptionGroupsRequest.php
app/Http/Requests/Api/V1/Admin/ListNotificationsRequest.php
app/Http/Requests/Api/V1/Admin/ListBranchProductsRequest.php
app/Http/Requests/Api/V1/ListPaymentsRequest.php
app/Http/Controllers/Api/V1/Admin/OrderController.php
app/Http/Controllers/Api/V1/Admin/OrderOperationController.php
app/Http/Controllers/Api/V1/Admin/ProductController.php
app/Http/Controllers/Api/V1/Admin/CategoryController.php
app/Http/Controllers/Api/V1/Admin/BranchController.php
app/Http/Controllers/Api/V1/Admin/CustomerController.php
app/Http/Controllers/Api/V1/Admin/PaymentController.php
app/Http/Controllers/Api/V1/Admin/PromotionController.php
app/Http/Controllers/Api/V1/Admin/CmsPageController.php
app/Http/Controllers/Api/V1/Admin/CmsBannerController.php
app/Http/Controllers/Api/V1/Admin/CmsGalleryController.php
app/Http/Controllers/Api/V1/Admin/RestaurantSettingsController.php
app/Http/Controllers/Api/V1/Admin/DashboardController.php
app/Http/Controllers/Api/V1/Admin/OptionGroupController.php
app/Http/Controllers/Api/V1/Admin/NotificationController.php
app/Http/Controllers/Api/V1/Admin/CouponController.php
app/Http/Controllers/Api/V1/KitchenOrderController.php
tests/Feature/RolesAndPermissionsTest.php
```
