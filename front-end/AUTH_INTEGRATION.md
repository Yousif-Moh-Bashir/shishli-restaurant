# Authentication/API foundation

Implemented 2026-10-04. Scope: API client, real auth, roles/permissions access foundation only. Existing catalog/cart/checkout/orders/offers/customers/settings/CMS remain preview data. Backend source was not edited.

1. **Actual Laravel endpoints**:

   | Method | Endpoint | Request | Success |
   | --- | --- | --- | --- |
   | POST | `/api/v1/register` | `name`, **required** `email`, optional nullable `phone`, `password`, `password_confirmation` | 201, UserResource; **no token** |
   | POST | `/api/v1/login` | **either** `phone` **or** `email`, plus `password` | 200, `{user, token, token_type: 'Bearer'}` |
   | GET | `/api/user` | No body; Bearer | 200, UserResource |
   | POST | `/api/v1/logout` | No body; Bearer | 200; current PersonalAccessToken deleted |
   | GET | `/api/v1/admin/me` | No body; Bearer, admin access | 200, AdminProfileResource |

   There is no `/api/v1/auth/me` route. `/api/v1/profile` is a separate customer profile domain and is not used for this foundation.

2. **Sanctum mode:** Personal Access Token. Login calls `createToken('api')->plainTextToken`; logout requires PersonalAccessToken and deletes only the current token. The token is opaque to Vue (standard Sanctum ID/secret representation); no format assumptions, JWT decoding, csrf-cookie or SPA cookie flow was added. Requests do not use `withCredentials`.
3. **Validation/body details:** Register name max255, email max255/unique, optional phone E.164/unique, password 8–72 with confirmation. Login accepts exactly one identifier (mutually prohibited email/phone), password required/max72, phone E.164. Existing Saudi local-phone UI input is normalized to +966; explicit international numbers remain supported. Login now accepts email in the same identity field, enabling email-only registered accounts. Register has the required email input with original field styling; phone is optional. No role/permission fields are sent.
4. **Response wrapper:** success `{success:true,message,data,errors:null}`; failures `{success:false,message,errors?}`. Laravel validation yields status422 and field-message arrays. Unknown/HTML responses are rejected. API modules explicitly unwrap known successes; response interceptors retain Axios response shape.
5. **General UserResource:** `uuid`, `name`, `email`, `phone`, `email_verified_at`, `created_at`, `updated_at`. It does **not** return roles, permissions or is_active. AdminProfileResource supplies UUID as `id`, name/email/phone, `is_active`, roles, permissions and capabilities. Store keeps these two actual resource shapes separately, not a fabricated merged resource.
6. **Seeded roles:** `super_admin`, `manager`, `cashier`, `kitchen`, `customer`; admin service also permits non-customer accounts with effective permissions, even without a listed staff role. No role selection is exposed in public registration. Customer role cannot be inferred as a returned role from UserResource; frontend role/permission helpers use AdminProfileResource when available.
7. **Page permissions:** Dashboard `dashboard.view`; Orders `orders.view`; Products `products.view`; Categories `categories.view`; Offers **`promotions.view`**, not legacy `offers.manage`; Customers `customers.view`; Settings **`settings.view`**, not `settings.manage`. Store checks only effective permissions from the server. No frontend role matrix or unconditional super_admin bypass: backend seeder normally grants super_admin all registered permissions, but no Gate::before bypass was found.
8. **New frontend files:** `.env.example`, local ignored `.env`; `src/api/http.js`, `auth.api.js`; `src/utils/apiError.js`, `authToken.js`; `src/constants/adminNavigation.js`; `src/router/authGuards.js`; `src/composables/useAuthForm.js`, `useSessionLogout.js`; `src/views/errors/ForbiddenView.vue`; `tests/auth.browser.mjs`; this document.
9. **Modified:** `.gitignore`, `src/stores/auth.js`, `src/router/index.js`, `src/main.js`, `src/App.vue`, `src/views/auth/LoginView.vue`, `RegisterView.vue`, `src/components/site/SiteHeader.vue`, `src/components/admin/AdminHeader.vue`, `AdminSidebar.vue`. Removed `src/composables/useAuthPreviewForm.js`. Build regenerates ignored `dist/`. No existing business-domain demo sources were changed.
10. **Client:** one Axios instance, `VITE_API_BASE_URL`, default same-origin `/api/v1`, 15-second timeout, `Accept: application/json`, central Bearer request interceptor. Content-Type is left to Axios for JSON/FormData. Public login/register deliberately carry no Bearer and cannot trigger session-expiry handling. Request-token capture prevents a stale response from invalidating a newer session. `me` derives the unversioned API root from configured `/v1`, preserving deployment prefixes.
11. **Credentials:** one localStorage key, `shishli_auth_token`; in-memory token is managed by the single Pinia store. No password, confirmation, OTP, raw auth response, user or permission snapshots are persisted. Password fields clear when sending a valid API request and on unmount; request payload passwords clear after completion. `.env` contains only the public local API URL and is ignored by Git. Browser storage failure falls back to an in-memory session, with `persistenceWarning` state; refresh persistence then cannot be guaranteed.
12. **Bootstrap:** router waits for the store's shared initialization promise. A stored token must pass `/api/user`, then `/admin/me` determines admin access. Expected admin403 preserves a valid customer session. Multiple initialization calls do not repeat network work, and ordinary route changes do not call me. Auth bootstrap has a lightweight loading screen rather than briefly displaying Login/Admin. Network/server failures fail closed without declaring a valid session or deleting a possibly valid stored token as if it expired.
13. **Login:** identifier/password → login → centrally persist issued Bearer → verify current user/admin access → safe intended route if permitted, otherwise first accessible existing admin page or site home. Staff without dashboard permission, e.g. kitchen with orders.view, lands on Orders. Invalid credentials leave the Login route unchanged; a public login401 does not expire an existing verified session. Verification failure after issuing a new token clears that unverified local session.
14. **Register:** exact allowed body; no role, no auto-login, no new-token persistence. Success stays on the existing form with a real success notice and existing Login link. Required email is the necessary contract addition; the layout/colors/fonts/images are retained. No phone/email verification workflow was invented.
15. **me:** actual `/api/user`, not an assumed auth/me; stored token alone is never isAuthenticated. `user` comes from the server; successful `/admin/me` supplies effective admin permissions. Customer admin403 is expected and does not clear current user.
16. **Logout:** central API method, current-token revocation, local credential/state removal and home navigation. Expired-token401 still removes local credentials. Network/500 failure displays an error and does not falsely claim server revocation. Local cart remains intact.
17. **401:** only requests sent with the current token can expire it. Login/register skip expiry. A matching protected401 clears session; if currently on a protected route it redirects once to Login with intended route. An old request's401 cannot remove newer credentials. Bootstrap invalid token redirects through the normal guard without loops.
18. **403:** no automatic logout. Expected admin-profile403 means no admin access. Page guards show `/forbidden`; form/API403 remains an ordinary actionable error. Backend remains authoritative for all future API authorization.
19. **422:** normalized field arrays reach the auth form and associated error elements; general error remains separate. Email-login backend email errors map to the existing identifier field. Submit clears stale errors and focuses the first invalid field. Accessible labels/autocomplete/aria-invalid/aria-describedby/loading-disabled states remain.
20. **Admin guards:** parent meta requiresAuth/requiresAdmin; child meta permission is generated from the same navigation definitions used by Sidebar. Direct URLs are guarded even when a sidebar item is hidden. Site remains public, including guest checkout; only Login/Register are guestOnly.
21. **Actual admin admission:** backend `AdminAccessService`: active user and either listed staff role or non-customer with any effective permissions. Frontend asks `/admin/me` rather than recreating this decision from email/name or an incomplete general UserResource. A successful active admin profile allows the admin shell, subject to each page's permission.
22. **Permission checking:** route meta and Sidebar call `auth.can(permission)`, using returned permission strings only. `hasRole`, `hasAnyRole`, `can` are UX helpers, not security. Unknown/missing permissions fail closed. If an admin has no permission for any migrated page, fallback is Forbidden, not an invented route.
23. **Removed fake auth:** preview form/composable and misleading Login/Register demo notices, fabricated AdminHeader name/role. Header name comes from `auth.user`; AdminHeader roles come from the server; both headers have real logout controls. Other demo notices/data remain deliberately unchanged.
24. **CORS/Sanctum:** Laravel was started locally with PHP's development server for testing. Health returned200 and `Access-Control-Allow-Origin: *` for the actual Vite origin. Browser real cross-origin Bearer requests worked. No CORS bypass/proxy hacks, cookie/CSRF workaround or Backend configuration edits were made. Production must use its actual configured API base URL; current local `.env.example` documents `/api/v1`.
25. **Registration/role security:** public RegisterRequest has no role field; RegisterUserAction explicitly assigns customer and User fillable excludes administrative fields. No public privilege escalation was found in the inspected contract; no administrative role or permission was created/changed in testing. Disabled login/current-user is actually401, not an invented403 rule.
26. **Live Laravel tests passed:** Register201 without auto-login (one email-only test customer created); Login via email; `/api/user`; customer `/admin/me`403; refresh restoration; customer denied direct Admin URL; Logout; invalid login401 staying on Login; actual Laravel422 wrapper; invalid token401 with local credential removal. No test password/token was printed or persisted in test reports. The test customer record remains in the local database; no delete-user endpoint was invented for cleanup.
27. **Not tested live:** staff/admin credentials were not supplied and no staff account was fabricated. Staff successful access, kitchen permission restrictions, seven Admin routes, stale401 race, duplicate submissions,429/500/network errors were tested with **browser transport fixtures**, separate from live results. Disabled-user login and actual429/500 outages were not induced against Laravel. These fixtures are isolated to `tests/`, never application auth. All authorized admin/public site routes rendered at1440/768/390 with no horizontal page overflow. Login/Register/Forbidden, real user headers, permission filtering and session/logout UI were checked.
28. **Build:** `npm run build` passed after implementation; 156 modules. Axios was already installed; no dependency/UI-framework additions.
29. **Diagnostics:** browser Runtime exception/Vue warning/error collection remained empty. Expected HTTP401/403/422 and simulated network/server errors occurred only in their explicit tests; browser network panels may show these expected denials, particularly customer `/admin/me`403. No unexpected API/resource404 or secret/debug logs were introduced.
30. **Remaining:** real staff/admin credential smoke test, deployment-specific API URL/history fallback and later business-domain integrations. Password recovery has no inspected backend route and remains an honest informational notice, not a fake request. Existing admin data remains preview despite real authentication. No persistent customer-role metadata is returned by general UserResource; admin permission helpers intentionally do not invent it. Local test customer cleanup can be performed separately through an authorized backend administration workflow.

## NEXT API INTEGRATION READINESS

Branches, Categories and Products are ready to start integration on this shared client/auth/error/permission foundation. Their APIs were not called or connected in this task. Validate real staff credentials/permissions in the target environment before production release.

## BACKEND → FRONTEND COVERAGE

Re-audited after the updated development rules: Laravel defines functionality; legacy HTML defines appearance. These rules are persisted in front-end/AGENTS.md and supersede legacy-only UI coverage restrictions.

| Backend feature found | Frontend coverage | Missing UI found/implemented | Authority / reason |
| --- | --- | --- | --- |
| Public registration | Register form and real POST /register | Required email field added; optional phone honored; real validation/success; no auto-login | Actual RegisterRequest/RegisterUserAction; server assigns customer |
| Email or phone login | Existing Login form and real POST /login | Existing identifier field expanded to email or phone; real loading/errors | Actual LoginRequest; exactly one identifier sent |
| Current user | Central session initialization and GET /api/user | Persistent session verification and loading state implemented | Token presence alone does not authenticate |
| Current-token logout | Reactive Site/Admin headers | Real logout controls, API revocation flow and local credential cleanup implemented | LogoutUserAction deletes current PersonalAccessToken |
| Admin identity/access | AdminHeader, protected AdminLayout | Real name/roles and Forbidden view implemented | GET /admin/me and AdminAccessService decide access |
| Effective permissions | Sidebar and direct-route guards | Permission-aware links, page guards and allowed landing route implemented | AdminProfileResource effective permission list; no role matrix/bypass |
| Login/register throttling and validation | Auth form error handling | Field422 and general401/403/429/network/server error handling implemented | Backend response/status; not new independent settings pages |
| Admin bootstrap/navigation metadata | Existing access foundation uses /admin/me and audited page permission definitions | No independent form/page needed for metadata endpoints | Internal session/navigation aggregation; do not fetch unrelated business modules solely for bootstrap |
| Forgot/reset password, OTP, phone verification, change password | No completed route found in api.php/web.php or matching auth controllers | No fake page or request created | A verification timestamp, notification event or throttle configuration is not a complete user-facing auth workflow |
| Customer profile GET/PATCH and guest-order claim | Found in CustomerProfileController/requests/resources | Pending separate Customer/account module, not part of this Auth-only integration | Prior task explicitly excluded Customers/Orders except auth necessities; new rules require full UI when that module is authorized, not automatic cross-module expansion |

**Existing UI covered:** Login/Register and Site/Admin headers; all seven Admin routes, Sidebar access and session initialization.

**Missing UI implemented:** required registration email, email-login capability, real session/logout controls, loading/field errors and Forbidden access state. These functional contract requirements take precedence over exact legacy field counts while retaining the design system.

**Internal features intentionally not exposed:** token database IDs/hashes, Sanctum configuration, Spatie permission internals, request IDs, middleware/queue implementation and authentication password hashes. None is a user-editable business control. No provider secret or token-management page is invented. Password is collected only in actual login/register forms; tokens remain centrally managed credentials.

**Domain features found but outside current scope:** Customer profile update allows name/email only and prohibits password/phone/roles changes. GET profile additionally contains statistics/default address; order claim is a customer-orders business action. These are recorded for full account/customer integration, without claiming they are completed or ignoring them because legacy HTML lacks their UI.

## Running verification

With Laravel at the API URL configured in `.env` and Vite at127.0.0.1:5173:

```powershell
node --experimental-websocket tests/auth.browser.mjs
```

The default run creates one ordinary test customer through the real Register API. To repeat transport/route/responsive tests without creating another account:

```powershell
$env:AUTH_SKIP_LIVE = '1'
node --experimental-websocket tests/auth.browser.mjs
```

Windows Chrome is detected at its standard path; override `CHROME_PATH` if needed. The test controls a hidden Chrome process, uses only test transport fixtures for staff/failure cases, and writes ignored results/screenshots/profile under `.auth-check/`. It never modifies Backend source or assigns a role. The initial full live run and a subsequent simulated-only run both passed. Chrome profiles are removed after inspection because they may contain browser session artifacts.
