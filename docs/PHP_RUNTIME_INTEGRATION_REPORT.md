# PHP Runtime Integration — implementation and verification

Date: 2026-10-16. Branch: `feature/ghcr-publishing`.

## Scope and current decision

Implemented a separate, non-publishing GitHub Actions integration workflow. Local
validation is **static/unit validation only**. No Docker command, container, local
database, volume or service was used for this implementation. Runtime integration
results are **pending GitHub Actions**, not passed or inferred from unit tests.
This work makes no security-readiness claim and does not resolve or bypass Trivy.

Existing GHCR/CI workflows, production Dockerfile/Compose, Laravel source,
dependencies, Secrets and Branch Protection were not changed. No staging, commit,
push, merge or PR operation was performed.

## New files

| File | Purpose |
| --- | --- |
| `.github/workflows/php-runtime-integration.yml` | PR to master and optional manual workflow, hosted Ubuntu 24.04, read-only repository permission, build without push, integration, always-attempt cleanup/report upload |
| `docker/ci/compose.php-runtime.yaml` | Independent MySQL 8.4, Redis 7.4, testing FPM and production-entrypoint FPM services |
| `.github/scripts/php-runtime-ci.py` | Hosted-runner guard, temporary credentials, image identity, probes, sanitized report, guarded cleanup |
| `.github/scripts/test_php_runtime_ci.py` | 29 unit/static regression tests with an injected command runner; never runs Docker |
| `.github/fixtures/php-runtime/bootstrap.php` | Guarded bootstrap and fixture-only autoloader |
| `.github/fixtures/php-runtime/console.php` | Real Laravel console kernel using read-only fixture provider |
| `.github/fixtures/php-runtime/CiServiceProvider.php` | Real scheduled callback plus failure-event evidence |
| `.github/fixtures/php-runtime/ProbeJob.php` | Real serialized Redis queue job and intentional failing variant |
| `.github/fixtures/php-runtime/probe.php` | Database/Redis/extension assertions and effect verification |
| `docs/PHP_RUNTIME_INTEGRATION_REPORT.md` | Scope, checks, limitations and GitHub review procedure |

Earlier uncommitted Trivy collector changes and untracked Phase 1 assessment
artifacts remain separate; they are not part of this file list.

## Isolation and credentials

- The orchestrator refuses execution before issuing commands unless Linux,
  `GITHUB_ACTIONS=true` and `RUNNER_ENVIRONMENT=github-hosted` are present, with
  strictly validated numeric run/attempt and a 40-character SHA. It offers no
  local-run or self-hosted bypass.
- Project: `shishli-php-ci-<run_id>-<run_attempt>`. There are no host ports,
  explicit shared container/network names, external networks, Docker-socket
  mounts, persistent named volumes or host database mounts. The project network
  is internal. MySQL data and Redis data use per-container tmpfs mounts.
- Only fixtures are bind-mounted, read-only at `/ci`. Laravel application and
  vendor continue to come from the built production image. No fixture is copied
  into that image; `.dockerignore` remains unchanged.
- The services use fresh random APP_KEY and database credentials in a mode-0700
  directory under `RUNNER_TEMP`, outside the checkout. Files have mode 0600 on
  Linux. MySQL root credentials are kept in a separate MySQL-only env file.
  Redis is unauthenticated only on the isolated, unpublished CI network; this
  does not validate production Redis authentication or TLS.
- Test credentials are created after the image build. They are not passed as
  build arguments or GitHub outputs, echoed, or uploaded. Subprocess output,
  environment, container inspect results, exception traces, failed-job payloads
  and application logs are never forwarded to logs or artifacts by the harness.
  No customer records or production credentials are used. Mail uses `array`;
  the image's notification channels default to disabled and the internal network
  prevents external provider traffic.

## Image and service identity

The workflow builds context `.`, existing `docker/php/Dockerfile`,
`target=production`, `linux/amd64`, with pull enabled and the runtime build stage
uncached, matching the relevant GHCR build settings. It loads a run-specific
local tag and never pushes. There is no registry login or `packages: write`.

Before Compose startup, image inspection must confirm linux/amd64, a valid
immutable image ID and matching `org.opencontainers.image.revision`. A valid
Buildx digest is mandatory. Compose then uses the immutable local image ID.
The report distinguishes `image_id` (Docker config/content ID) from
`build_digest` (Buildx manifest/index digest); they are not interchangeable.
Running service image IDs, including MySQL and Redis, are recorded separately.

The same Dockerfile does **not** guarantee byte-identical GHCR and integration
images: builds occur separately and external apt/PECL/Composer/base references
may change. This workflow validates its own recorded candidate, not an already
published GHCR artifact. Changing production image selection is out of scope.

## Integration checks performed on GitHub

| Check | Required observable evidence |
| --- | --- |
| Compose configuration | `config --quiet` using generated CI-only env files; no `/etc/shishli` files |
| Services/FPM | All four expected services healthy; FPM FastCGI ping; inspected image IDs/ownership labels |
| Runtime/dependencies | PHP 8.3, Laravel 13, PDO MySQL/SQLite, Redis, GD, intl, zip, OPcache, pcntl, mbstring, bcmath loaded; SQLite in-memory query |
| Connections | Real application-user MySQL query and MySQL 8.4 version; Redis connection and Redis 7.4 version |
| Fresh migrations | Empty application schema before migrations, real existing migrations twice plus status, required migration/failed_jobs tables; no seeders, `migrate:fresh`, reset or production migrations added |
| Redis TTL/locks | Set/read/positive TTL followed by bounded observed expiry; second lock holder denied until first releases |
| Connection failures | Wrong MySQL password must yield PDO access-denied 1045; wrong Redis port must yield RedisException; unrelated bootstrap/assertion failure does not count as expected failure |
| Missing worker | Dispatched job present in Redis, no DB effect, effect verifier explicitly rejects with code 21 before any worker runs |
| Queue success | Real `queue:work redis --queue=runtime-ci --stop-when-empty --tries=1 --timeout=20 --max-time=40`; unique new DB marker after processing |
| Queue failure | Intentional exception, exactly one matching failed_jobs record, expected connection/queue/token/exception marker, empty queue and no success marker; worker exit zero alone is insufficient |
| Missing Scheduler | `schedule:run` without fixture registration token succeeds but produces no marker, and the effect verifier must reject it |
| Scheduler success | New token registered through real Schedule callback, `everyMinute`, real `schedule:run`, DB marker afterward |
| Scheduler failure | Callback throws; actual ScheduledTaskFailed event records a distinct failure marker, success marker absent, success verifier rejects |
| Production entrypoint | Separate same-image FPM service with APP_ENV=production becomes healthy after the original entrypoint caches config/routes/views; config/routes caches and production/database host configuration verified |

Fixture bootstrap requires `SHISHLI_RUNTIME_CI=1`, `APP_ENV=testing`, hostnames
`mysql`/`redis` and the strictly named test database. The fixture ledger is
created only inside that fresh CI database, not by modifying application
migrations. Fresh random tokens prevent old success records from satisfying a
later check. There is no Queue/Redis/Scheduler fake in the integration probes.

The negative connection probe uses code 20 only for its expected connection
exception. A missing effect uses code 21 only after a successful DB query.
Other failures use code 10; bootstrap guard uses 90. The Python orchestrator
checks both code and fixed marker. Expected negatives are asserted within the
test, not suppressed using Actions `continue-on-error` or shell `|| true`.

Laravel's ScheduleRunCommand catches callback exceptions and may return zero.
The failure probe therefore requires the real failure event and absence of a
success effect, rather than treating a successful process exit as success.

## Cleanup and artifacts

The cleanup step uses `if: always()`. A validated initialization marker must
match the current run before any resource inspection/removal. Startup refuses
pre-existing project resources. Cleanup selects resources by the exact Compose
project label, then verifies full IDs, Compose service/network labels, the
dedicated owner and run labels, an internal network, no persistent/anonymous
volume mounts and no foreign network endpoints. **The entire inventory must
validate before the first deletion.**

Only inspected full container IDs and network IDs are removed. There is no
`prune`, broad `down`, global volume removal or deletion based on a name prefix.
Unexpected volumes or identity mismatches fail closed. A second inventory must
be empty. CI credential files are removed even when cleanup fails; the report
records `cleanup=failed` and the step fails.

Only `report.json` is uploaded, never its parent directory. Its fixed schema
contains run, source SHA, validated image IDs/digest, predefined check names and
states, overall integration state, cleanup state and allowlisted error codes.
Invalid report contents are rejected and replaced with a sanitized failure
report. Raw command/inspect output is kept in process memory only.

Integration failures remain failed Actions steps. Cleanup and upload are
attempted afterward without changing that result. A build failure yields
`status=not_run` and empty image/check evidence; it is not an integration pass.
`status=passed` describes the probes; `cleanup` must also be `passed` when
reviewing the final artifact. Cleanup failure fails the job independently.

## Local verification actually executed

| Verification | Result |
| --- | --- |
| Python 3.14 unittest, `test_php_runtime_ci.py` | 29 passed; all process runners injected/mocked |
| PHP 8.3.29 `-l`, all five fixture PHP files | Passed |
| actionlint v1.7.12, new workflow | Passed; optional shellcheck/pyflakes disabled because unavailable locally |
| Symfony YAML parser from installed vendor, new Compose file | Passed, including resolved YAML anchors, expected four services, no host ports/names/privilege, internal network and read-only fixture assertions; no Laravel boot |
| `git diff --check`, plus whitespace checks of the new files | Passed; pre-existing collector files emit Git LF/CRLF conversion warnings only; no global Git settings changed |
| Docker build / Compose CLI validation / services / migrations / queue / schedule | **Not executed locally**, deliberately |

Unit coverage includes local-run refusal, invalid identity, unsafe temp path,
duplicate prepare, credential/report separation, image identity/digest failures,
missing/unhealthy services, pre-existing resources, false-positive worker and
scheduler outcomes, failed-job evidence failure, incomplete overall checks,
sanitized internal errors, forged cleanup marker, foreign labels/endpoints,
unexpected volumes, deletion failure, exact-ID deletion and invalid report data.

## GitHub review procedure and remaining limits

1. After user review and a separately authorized push, a PR targeting master
   triggers **PHP Runtime Integration / integration**. `workflow_dispatch` is
   optional; GitHub normally requires its workflow file on the default branch
   before exposing the manual Run workflow control.
2. Confirm the build used the intended SHA, existing production Dockerfile and
   linux/amd64; open **Run isolated runtime integration** and **Clean only verified
   CI resources**. Fixed failure messages point to the report's check/error code.
3. Download `php-runtime-<run_id>-<attempt>` and inspect its sole `report.json`.
   Require matching SHA, image ID and Buildx digest, all 16 checks passed,
   `status=passed` and `cleanup=passed`. If the build or harness did not run,
   `not_run` is evidence of missing coverage, never acceptance.
4. Inspect GHCR/Trivy separately. This workflow has no dependency on publishing,
   no publication permission and no security exceptions. It does not make its
   check required in Branch Protection or change any existing publishing gate.

Pending verification includes real Compose/Docker/Buildx compatibility, fresh
MySQL migration behavior, PHP module availability in the built image, phpredis
responses, fixture deserialization by the Redis worker, actual scheduler events,
resource cleanup and artifact upload on a hosted runner. Static/unit checks
cannot prove these behaviors.

This is a bounded integration test, not queue load/soak testing, daemon restart
or retry/backoff/concurrency qualification. It tests `schedule:run`, not prolonged
`schedule:work`, host cron or timezone/DST operation. The custom job/callback
prove image/framework/service wiring, not every production notification/business
job. No Nginx, browser, customer payment flow, HTTPS, backup restoration or
production Redis authentication is exercised. Trivy readiness remains a separate
blocking assessment. A runner outage or forced termination can prevent even
`always()` cleanup/upload; the GitHub-hosted runner is disposable, and cleanup
must not be generalized to local or shared hosts.

Rollback before commit: omit these ten new files from the proposed change. After
a future authorized commit, revert that isolated commit through normal review.
No production configuration rollback or database migration rollback is needed.
