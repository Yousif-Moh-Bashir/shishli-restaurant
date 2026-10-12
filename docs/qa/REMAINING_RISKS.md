# Remaining risks / NOT TESTED

| Item | Status / reason | Required next verification |
|---|---|---|
| Production deployment readiness | NOT TESTED; audit uses local development/test environments | Staging HTTPS, debug off, correct CORS, filesystem permissions, secrets, backups/restore and rollback |
| Existing known test accounts | NOT TESTED; preventive seed fix does not delete previously deployed accounts | Inspect and disable any deployed fixture accounts through an authorized deployment procedure |
| External payment provider / refunds | NOT TESTED; registered fake adapter tests only, no live adapter/refund workflow | Provider sandbox integration, signed real payload samples, reconciliation, refund policy |
| Actual notification delivery | NOT TESTED; email/SMS disabled or faked to avoid sending to people | Controlled staging recipients, credentials, queue worker/retry/deduplication and delivery receipts |
| Production performance | NOT TESTED; local tests with SQLite/development servers cannot predict deployment load | MySQL EXPLAIN, indexes under actual query distributions, bounded staging load, cache invalidation and worker metrics |
| Large logo/reference images | OPEN QA-006, Low; 783 KB / 1.645 MB emitted by build | Responsive asset sizes with visual comparison and slow-network measurements |
| Exhaustive endpoint/state/role coverage | NOT VERIFIED; route inventory and many business tests do not establish completeness | Prioritize untested route branches, role-object permutations and destructive-operation dialogs |
| Browser/device diversity | NOT TESTED beyond headless Chrome emulation | iOS Safari, Android Chrome, real location permission denial/timeouts, orientation and touch |
| OTP/recovery/live driver GPS | Not implemented; no testable existing workflow | Scope and design before claiming feature availability |
| Global admin staff branch assignment | Not implemented in current staff model; driver branch isolation verified | Decide whether general staff branch restriction is a business requirement |
| LocalStorage bearer tokens | Existing architecture; residual XSS consequence | Review CSP and consider protected session architecture with its CSRF requirements if threat model requires |
| Dependency audits | Point-in-time zero advisories; no complete supply-chain guarantee | Keep lockfiles and automated periodic security checks |

No production release or deployment was performed. Cash checkout/internal delivery passing locally supports further staging validation; it does not establish launch readiness or complete security.
