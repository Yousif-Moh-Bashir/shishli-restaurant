# Shishli frontend development rules

## Functional and visual authorities

- Existing Laravel backend is the functional source of truth. Legacy HTML is the visual design reference only.
- For each module in scope, implement all completed backend business features requiring user interaction, including missing controls, sections and pages. Absence from legacy HTML is not a reason to omit a feature.
- Reuse existing Shishli colors, Tajawal font, spacing, radii, buttons, inputs, tables, cards, modals, layouts, Sidebar and RTL behavior. New UI must work on desktop, tablet and mobile. Do not introduce a UI framework or redesign unrelated pages.
- Do not modify legacy source. Do not modify backend source unless a later user instruction authorizes it; document blocking backend bugs instead.

## Audit before implementation

- Inspect actual routes, controllers, requests, resources, models, relationships, actions, services, enums, events, policies, permissions, validation and business rules for the module.
- Build an internal feature matrix: backend feature, existing frontend UI, missing UI, required UI, permission and endpoint. Use it to implement coverage, not merely propose a plan.
- CRUD is not sufficient: inspect actual business actions (activate/deactivate, transitions, cancellation, retries, reordering, primary image, assignment/detachment, collection, publication and other supported operations).
- Use actual request fields, resource shapes, enum values, query parameters and permission names. Never invent endpoints, roles, permissions or enum values.

## UI scope and authority

- Prefer nested sections/tabs/modals in the parent module for images, options and branch product configuration. Add standalone pages/Sidebar entries when the workflow warrants them.
- Respect the intended audience: public, customer, staff, manager or super admin. Sidebar and routes must use actual permissions, and actions must respect permissions and server-provided available_actions where supplied.
- Laravel remains the authority for authorization, pricing, eligibility, availability, state transitions and other business rules. Do not duplicate backend state machines in Vue.
- Database fields/jobs/events do not automatically require UI. Expose business operations, not internal implementation details, credentials, secrets, editable database IDs or timestamp forms.
- Completed customer features need appropriate Site UI as well as Admin coverage where applicable.

## Real integration and verification

- Integrating a module means backend audit, missing UI implementation, real API calls, loading states, errors, field-level Laravel422 validation, permissions, responsive behavior and appropriate tests/build.
- Use the existing central Axios/auth/error foundation and organized domain API modules. No hardcoded API URLs or direct scattered Axios calls in views.
- Use Pinia for genuine shared state; extract components for actual reuse, not arbitrary fragmentation.
- Never show mutation success before a real successful response. Prevent duplicate sensitive submissions; use suitable confirmations for destructive actions.
- Update state from server responses or refetch after mutations. Use backend pagination metadata, search and supported filters for integrated modules.
- Remove demo data only for the module successfully integrated. Preserve other modules' demo data until their own integration task.
- Keep each task within its authorized module scope. Do not start other module integrations automatically.
- Run npm run build after implementation and resolve errors. Check appropriate runtime/browser behavior without logging tokens, passwords or sensitive responses. Distinguish live API tests from transport fixtures.

## Required report section

After each module, include BACKEND → FRONTEND COVERAGE:

- Backend features found.
- Existing UI covered.
- Missing UI discovered.
- Missing UI implemented.
- Backend-only internal features intentionally not exposed, with reasons.

## Current baseline

- Auth/API foundation is implemented. Inspect AUTH_INTEGRATION.md and actual code for contracts and verification limits.
- Real auth endpoints: POST /api/v1/register, POST /api/v1/login, GET /api/user, POST /api/v1/logout; GET /api/v1/admin/me supplies admin roles/permissions.
- Register requires email and returns no token. Login uses Sanctum Bearer Personal Access Tokens. Do not add cookie/CSRF flow.
- No completed forgot/reset password, OTP, phone verification or password-change routes were found in the current audit. Do not fabricate those features; recheck actual backend during future auth work.
- Customer profile/address/order-claim features are separate domains to cover when their modules are authorized. Branches, Categories, Products and all other business data integrations have not been started.
