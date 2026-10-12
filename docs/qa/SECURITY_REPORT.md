# Security review

Local application review and tests; no claim of complete security or production penetration testing.

## Confirmed finding

One new application security defect: **QA-001, Medium**, known customer test account created by production seeding. Fixed by environment guard with a failing-then-passing regression. QA-004 is a separate Medium test-tool isolation defect, not a customer-facing authorization vulnerability. Existing deployed test accounts require inspection on staging/production by the operator; this audit did not modify them.

## OWASP-oriented evidence

| Area | Reviewed / tested controls | Limitation |
|---|---|---|
| Broken access control / IDOR | Sanctum missing/invalid token route matrix; customer blocked from admin/driver; existing customer ownership, guest token, driver branch/assignment and roles tests | Exact authenticated role × object × action matrix for every route NOT VERIFIED |
| Authentication / mass assignment | Expired/inactive/invalid bearer handling, admin role escalation tests, explicit FormRequest fields and resource filtering | OTP and recovery absent; independent credential attack assessment NOT TESTED |
| Injection / XSS | Eloquent/parameterized query review, bounded filters, plain-text rendering, safe content URLs, CMS upload/security tests; static trusted icon HTML | Independent SQLi scanner and CSP penetration review NOT TESTED |
| Sensitive data | Pre-accept driver customer details hidden; own assigned order required; immutable order coordinate snapshot; customer/guest resource boundaries | Browser tokens use localStorage, hence residual XSS exposure remains; do not log tokens |
| File upload | Image MIME/type/size checks, generated names, media path/traversal controls; real isolated upload/display/delete | Production reverse-proxy limits and public storage execution permissions NOT TESTED |
| Payment integrity | Server money/price calculation, payment amount/currency/reference verification, signature before webhook mutation, duplicate/out-of-order event tests | Only fake registered provider used; no live provider adapter/charge/refund tested |
| Race conditions | MySQL row-lock tests for inventory, driver accept, staff edits; new single-use coupon concurrent checkout | Production isolation/replication topology NOT TESTED |
| API resource abuse | Endpoint throttles, bounded pagination/exports/body lengths, 429 regression handling | Distributed rate limiting/load resistance NOT TESTED |
| Dependency risk | Composer: zero reported advisories/abandoned packages; npm: zero known vulnerabilities reported | Point-in-time advisory results, not proof dependencies are safe |
| Misconfiguration | Separate cache build succeeds; source authorization and safe error contracts reviewed | Real deployment debug/CORS/HTTPS/headers/secrets/backup policy NOT TESTED |

Driver status actions lock and verify user/order assignment, branch, availability and one active order. Reassignment has a distinct permission and history; cash collections use the existing payment service and immutable collection/settlement ledger, not driver personal delivery income. Food preparation, delivery and payment remain separate state machines.

Guest tracking is capability-based: credential invalid/expired cases are generic; query-string token disclosure is avoided. Mutation and identity tests use synthetic data. No external messaging, payment charge, real GPS collection or high-volume attack was run.

## Dependencies and configuration

`composer audit --format=json` returned empty `advisories` and `abandoned`. `npm audit --json --registry=https://registry.npmjs.org` reported zero vulnerabilities (124 total dependencies in metadata). The HTTPS registry was explicit because the machine's default HTTP registry returned 426; global npm configuration was not changed.

No tracked real `.env` credentials were found in the inspected Git file list; this does not establish the absence of secrets throughout historical commits or deployed infrastructure. Separate QA config cache was cleared immediately. Deployment configuration was not changed.
