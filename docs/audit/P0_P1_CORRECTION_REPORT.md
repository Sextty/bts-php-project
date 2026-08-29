# BTS Bank — P0/P1 blocking correction report

Date: 23 August 2026  
Scope: only the blockers and directly related correctness defects from
`P0_P1_IMPLEMENTATION_REVIEW.md`.

## Decision

**SAFE TO CONTINUE TO NEXT ROADMAP PHASE.**

This means the blocking regressions identified by the implementation review are corrected and
verified. It does **not** mean that BTS Bank is certified, production-ready, or permitted to
process real money. The exact next phase is **Phase 2 — security and compliance hardening**. No
Phase 2 work was started in this batch.

## Verification matrix

| Check | Result | Evidence |
|---|---|---|
| Complete Laravel suite | PASS | 390 passed, 2 MariaDB-only tests skipped under SQLite, 2,963 assertions |
| Corrected-area regression suite | PASS | 91 passed, 347 assertions |
| Fresh MariaDB migration | PASS | repository schema plus all later migrations applied on XAMPP MariaDB 10.4.32 |
| Controlled upgrade migration | PASS | every pre-batch status and representative relationship preserved; an additional realistic rehearsal preserved 1,020 users, 1,327 applications and 647 appointments |
| MariaDB appointment concurrency | PASS | dedicated disposable database; capacity, slot and attempt races passed |
| MariaDB audit concurrency | PASS | concurrent writers preserved one valid chain |
| MariaDB concurrency total | PASS | 2 tests, 47 assertions |
| State/audit rollback | PASS | forced audit failure rolled back state, branch and appointment |
| Notification retry/failure | PASS | temporary retry, eventual success, permanent failure, `failed_jobs`, replay and deduplication covered |
| Config cache and health | PASS | `config:cache` plus 11 tests, 72 assertions; cache cleared afterwards |
| Client lint/types/build | PASS | ESLint, `tsc --noEmit`, Next.js production build |
| Staff lint/types/build | PASS | ESLint, `tsc --noEmit`, Next.js production build |
| Admin lint/types/build | PASS | ESLint, `tsc --noEmit`, Next.js production build |
| Security Center lint/types/tests/build | PASS | ESLint, TypeScript, 8 Vitest tests, production build |
| Isolated Playwright | PASS | 9/9 Chromium scenarios in 4.6 minutes against a disposable MariaDB database |
| Dependency audits | PASS | Composer and root/client/staff/admin/SC npm audits: zero reported vulnerabilities |
| Secret/path scan | PASS | no credential signature and no tracked user-specific path found; only `.env.example` is tracked |
| Diff whitespace check | PASS | `git diff --check` found no whitespace defect |

The global Pint style gate still reports unrelated historical formatting debt. Every PHP file
changed by this correction was formatted and the corrected-area tests were rerun afterwards.
Unrelated files were deliberately not reformatted because this batch forbids scope expansion.

## 1. Appointment scheduling — PASS

### Root cause

The manual staff endpoint implemented appointment rules in its controller and did not share the
transaction and lock protocol used by automatic scheduling. It could race on branch capacity,
slot occupancy, application state and `attempt_number`.

### Implementation

- `AppointmentSchedulingService` is the sole mutation protocol for automatic, customer-response
  and manual staff scheduling.
- Lock order is application, branch, then appointment rows.
- Branch assignment, previous appointment cancellation, attempt allocation, capacity validation,
  appointment creation and application transition execute in one transaction.
- Deadlock retries are bounded. Exact repeated manual requests are idempotent.
- Operational staff can schedule only inside their branch; global roles retain cross-branch
  behavior. The branch selector now follows the same rule.
- The unique `(credit_application_id, attempt_number)` constraint remains. Its migration performs
  a duplicate preflight and fails clearly instead of silently deleting or rewriting data.

### Files

`backend/app/Services/AppointmentSchedulingService.php`,
`backend/app/Http/Controllers/Staff/ReportController.php`,
`backend/database/migrations/2026_08_23_140100_enforce_unique_appointment_attempts.php`, and the
appointment/MariaDB/branch-isolation tests.

### Evidence

SQLite behavior tests cover automatic scheduling, manual scheduling, rollback, capacity,
monotonic attempts and branch isolation. Real MariaDB subprocess tests cover same slot, two
applications, same application, attempt races, exhaustion and audit-chain concurrency. Playwright
executes staff manual scheduling in the isolated end-to-end workflow.

### Compatibility and limitations

Existing API routes and appointment payloads remain compatible. Invalid concurrent requests now
return a controlled conflict instead of creating inconsistent rows. Very high-contention
production lock timing still needs load testing in Phase 5.

## 2. Notification delivery reliability — PASS

### Root cause

`DeliverNotificationJob` caught provider exceptions and returned normally. Laravel then removed
the job as successful, preventing retries and `failed_jobs` visibility.

### Implementation

- The persisted in-app notification remains the synchronous source of truth.
- A `notification_deliveries` row records channel, state, attempts, timestamps and the last safe
  error category.
- Retryable errors are rethrown; permanent errors are recorded explicitly.
- The job defines three attempts, backoff of 5/30/120 seconds and a 30-second timeout.
- Delivery state provides application-level idempotency for replayed jobs.
- `notifications:reconcile` requeues incomplete deliveries and can explicitly include corrected
  permanent failures.
- Exhausted jobs update delivery state and remain visible through Laravel `failed_jobs`.

### Files

`backend/app/Jobs/DeliverNotificationJob.php`,
`backend/app/Models/NotificationDelivery.php`,
`backend/app/Services/NotificationService.php`, notification channel contracts/exceptions,
`backend/app/Console/Commands/ReconcileNotificationDeliveries.php`, and migration/tests.

### Evidence

Tests prove temporary failure, eventual success, permanent failure, exhausted worker failure,
`failed_jobs`, reconciliation and duplicate retry behavior. External provider failure does not
roll back the banking workflow.

### Compatibility and limitations

Inbox/API behavior remains unchanged. External delivery is eventual when a non-sync queue is
selected. Provider-side exactly-once delivery still depends on provider idempotency support;
application replay is idempotent after a delivery is recorded successful.

## 3. Playwright and authentication — PASS

### Root cause

The suite depended on an undeclared local Playwright install, read obsolete `localStorage`
tokens, assumed three appointment attempts and used the normal development environment.

### Implementation

- Root package metadata declares `@playwright/test` and locks it.
- Tests use `sessionStorage` and the current French UI/API contracts.
- Appointment attempt limits come from seeded domain behavior, not a stale hardcoded value.
- `scripts/run-e2e-isolated.ps1` creates a PID-scoped MariaDB database, generates an ephemeral app
  key, seeds synthetic fixtures, starts isolated API/worker/Reverb/four portal processes, and
  drops the database in `finally`.
- The suite uses explicit `.test` identities and no external customer data.
- E2E Next build directories are isolated and ignored by Git/lint.

### Files

Root `package.json`/lock, `playwright.config.ts`, `scripts/run-e2e-isolated.ps1`, `e2e/*`, portal
authentication helpers, SC token tests, and portal ESLint/Next configuration.

### Evidence

Nine browser scenarios pass: OTP registration/login, refresh/logout, full application and
document flow, staff review/rejection/branch isolation/manual scheduling, admin global decision,
appointment acceptance/locking, live Reverb chat without reload, SC authorization and suspended
account rejection.

### Compatibility and limitations

Bearer sessions remain tab-scoped by design. Google backend behavior is covered by six automated
tests, but a real Google browser sign-in is not automated because it would require an unsafe
external account/secret.

## 4. Health, worker and scheduler — PASS

### Root cause

The health service read runtime `env()` values and inferred worker state from old queued jobs.
That was wrong with cached configuration and could not distinguish an empty queue from an offline
worker.

### Implementation

- Runtime settings live in `config/operations.php`; services use `config()` only.
- Queue workers and the scheduler write independent heartbeats.
- Health distinguishes ready, delayed, reserved, failed and stale jobs.
- Empty queue plus fresh heartbeat is healthy; missing/stale worker heartbeat is distinct.
- Database, cache, storage and configured Reverb connectivity are checked with sanitized output.
- Scheduler and worker requirements are environment-controlled and cache-safe.

### Files

`backend/config/operations.php`, `backend/app/Services/HealthCheckService.php`,
`backend/app/Services/OperationalHeartbeatService.php`, provider/console scheduling hooks,
`.env.example`, health tests and operations documentation.

### Evidence

`php artisan config:cache` succeeded; all config/health tests passed while cached. The isolated
E2E environment ran a real database worker and Reverb server.

### Compatibility and limitations

Local synchronous queue behavior remains supported. Production must supervise `queue:work`, the
scheduler and Reverb; the application detects their failure but does not replace an OS process
supervisor.

## 5. Directly related corrections — PASS

- SC migrates legacy token, role and user metadata together and clears both storage locations on
  logout; Vitest and E2E cover migration, refresh, tab scope and logout.
- Duplicate `CORS_ALLOWED_ORIGINS` was removed. Localhost patterns are enabled only by the explicit
  development switch and do not leak automatically into production.
- ClamAV start, permission, process and timeout exceptions normalize to the scanner's defined
  unavailable/error policy. Optional local development uploads remain usable.
- Appointment uniqueness migration performs a non-destructive duplicate preflight.
- Generated E2E directories are excluded from lint/source control.

Real ClamAV/EICAR integration was not executed because ClamAV is not installed in this XAMPP
environment. Production must use required mode and validate its daemon before accepting uploads.

## 6. State and audit atomicity — PASS

`CreditApplicationStateMachine` now persists the business transition and mandatory audit row in
the same database transaction. Review services use that protocol. A forced audit persistence
failure leaves status, branch and appointment unchanged.

## 7. Audit integrity foundation — PASS

- Audit HMAC has dedicated configuration and an active key version.
- New rows and the chain head persist the key version.
- Legacy protected rows retain the legacy verification path; they are not silently rewritten.
- A missing chain head fails closed for writes and verification.
- Chain-head locking serializes concurrent writers; MariaDB concurrency verification passes.

This is a tamper-evident application-level chain, not external immutable compliance evidence.
Future rotation must retain every historical key version. External sealing and database-role
separation remain Phase 2/operations work.

## Remaining non-blocking limitations

1. No real Google IdP or ClamAV daemon was used in automated E2E.
2. Client/staff/admin have no separate component-unit runner; their changed workflows are covered
   by TypeScript, builds and isolated Playwright. SC has Vitest coverage.
3. The GitHub workflow was reviewed but not executed from this local machine. Full MariaDB
   concurrency and isolated E2E remain local commands unless added as CI jobs later.
4. Worker/Reverb/scheduler supervision and alert delivery require deployment infrastructure.
5. The audit chain is not yet externally sealed and the CSP still permits inline styles/scripts
   required by the current Next.js applications.
6. Repository-wide Pint has historical failures outside this correction scope; corrected PHP
   files pass formatting.

None of these reintroduces the four blocking regressions. They remain mandatory considerations
before production and are not authorization to process real banking funds.
