# BTS Bank — P0/P1 Implementation Review

> Historical review: its blocking findings were corrected and revalidated on 23 August 2026.
> See `P0_P1_CORRECTION_REPORT.md` for the current evidence and decision. The findings below are
> retained as the pre-correction baseline.

**Review date:** 2026-08-23  
**Review type:** post-implementation hardening audit; no feature or corrective code changes  
**Final recommendation:** **NOT SAFE TO CONTINUE**

## 1. Executive conclusion

The batch materially improves schema reproducibility, workflow enforcement, branch isolation,
browser headers, upload inspection, audit tamper detection, and asynchronous broadcasting. The
backend regression suite is green, all four portals compile, and both a zero-to-current MariaDB
migration and a realistic pre-batch upgrade preserved data.

The batch is not yet production-safe as a complete unit. Four blocking regressions/gaps remain:

1. `Staff\ReportController::scheduleAppointment()` bypasses `AppointmentSchedulingService` and
   its row-lock protocol. Concurrent manual scheduling can double-book a branch slot, race on
   `attempt_number`, partially change application state, and return a generic duplicate error.
2. `DeliverNotificationJob` catches and swallows delivery exceptions. The queue records the job
   as successful, so email/SMS delivery can disappear permanently with no retry or failed-job
   evidence.
3. Existing Playwright tests are stale: they still read the bearer token from `localStorage`,
   assume three appointment attempts while the model permits five, and `@playwright/test` is not
   declared in the root package. A clean `npm ci` cannot be relied on to run them.
4. Health checks detect queue age, not a worker heartbeat. The currently running local API is
   correctly degraded (17 old jobs and Reverb unavailable), but custom health/Reverb environment
   reads occur outside configuration files and are unsafe with Laravel config caching.

No complete batch revert is recommended. The correct decision is to keep the safe foundations
and adjust the specific failures listed in section 7 before further feature work.

## 2. Scope and evidence

### Commands and outcomes

| Check | Result | Evidence |
|---|---:|---|
| Full Laravel suite | PASS | 369 tests, 2,883 assertions, 48.94 s |
| MariaDB migration from every migration file, no schema dump | PASS | XAMPP MariaDB 10.4.32, all migrations ran from zero |
| Normal `migrate:fresh` with repository schema dump | PASS | dump loaded, all later migrations ran |
| Realistic pre-batch upgrade | PASS | 17/17 statuses, 17 applications, one appointment, one document and one legacy audit row preserved |
| Status schema after upgrade | PASS | `varchar(50)`, non-null, default `DRAFT` |
| Concurrent audit inserts | PASS | 20 processes, 20 rows, 20 unique hashes, chain verified |
| Concurrent automatic appointment proposals | PASS | two applications at one branch received distinct slots; no deadlock |
| Concurrent decision on one appointment | PASS | one accepted decision and one rejected duplicate; one acceptance audit row |
| Worker offline retention | PASS | four jobs remained in the database queue |
| Reverb failure handling | PASS with operational failure | two broadcasts moved to `failed_jobs` when Reverb was unavailable |
| Client lint / TypeScript / build | PASS | production build completed |
| Staff lint / TypeScript / build | PASS | production build completed |
| Admin lint / TypeScript / build | PASS | production build completed |
| Security Center lint / TypeScript / Vitest / build | PASS | 6 Vitest tests; production build completed |
| Runtime portal smoke | PASS, limited | ports 3000–3003 returned 200 and security headers; login/SC pages rendered without console errors |
| CORS runtime matrix | PASS | all localhost and 127.0.0.1 origins on ports 3000–3003 returned exact allow-origin and credentials headers |
| Playwright discovery | PASS | six scenarios discovered |
| Full Playwright execution | NOT RUN | live ports point to the user's shared database; suite has no isolated bootstrap and has stale assertions |
| Composer validation/audit | PASS with warning | no advisories; unbounded `twilio/sdk` constraint remains |
| npm production audits | PASS | zero reported vulnerabilities in all four portals |
| Tracked secret/local-path scan | PASS | no credential pattern or `C:\Users\wassi` path found; real `.env` is untracked |

### Important test limitations

- Local MariaDB is **10.4.32**, while CI declares **11.4**. The migration chain passed locally but
  the CI version was not executed on this machine.
- The realistic upgrade snapshot covers every status and representative related rows, but no
  pre-migration backup of the user's already-upgraded database exists. Therefore historic loss
  on that specific prior run cannot be proven retrospectively.
- Frontend runtime smoke checks are not authenticated end-to-end journeys. Google Identity was
  not configured, Reverb was offline, and no real portal credentials were used.
- SQLite feature tests do not prove InnoDB row-lock behavior; separate MariaDB concurrency probes
  were therefore run for the automatic appointment and audit paths.

## 3. Area review matrix

| Area | Result | Decision |
|---|---:|---|
| Credit application status migration | PASS | KEEP |
| `CreditApplicationStateMachine` | PASS with transactional gap | ADJUST |
| Skipped workflow stage prevention | PASS | KEEP |
| Branch isolation/global roles | PASS | KEEP |
| Suspended customer/staff behavior | PASS with session gap | ADJUST |
| Appointment locking and uniqueness | **FAIL** | ADJUST |
| Audit integrity chain | PASS for chaining; FAIL for atomic audit guarantee | ADJUST |
| ClamAV/malware scanning | PASS for stated policy; incomplete failure handling | ADJUST |
| Document upload/download compatibility | PASS | KEEP |
| Queue configuration and requirements | PASS technically; FAIL operational readiness | ADJUST |
| Async notifications | **FAIL** | ADJUST |
| Async Reverb broadcasts | PASS with retry/runbook gap | ADJUST |
| Worker failure behavior | **FAIL** | ADJUST |
| `sessionStorage` token migration | **FAIL** compatibility verification | ADJUST |
| CSP/security headers | PASS for static/runtime smoke; incomplete integration proof | ADJUST |
| CORS for four portals | PASS | KEEP |
| CI MariaDB configuration | **FAIL** as a sufficient gate | ADJUST |
| Health checks/scheduler/worker requirements | **FAIL** as production readiness proof | ADJUST |
| Secrets and environment changes | PASS with local `.env` cleanup required | ADJUST |

## 4. Detailed review

### 4.1 Credit application status migration — PASS / KEEP

1. **Behavior changed:** on MySQL/MariaDB, `credit_applications.status` is explicitly normalized
   to `VARCHAR(50) NOT NULL DEFAULT 'DRAFT'`.
2. **Why:** all 17 domain statuses must be persistable; the original customer-only enum was not
   sufficient as a durable source of truth.
3. **Files:** `backend/database/migrations/2026_08_23_140000_make_credit_application_status_schema_explicit.php`,
   `backend/app/Models/CreditApplication.php`, MariaDB CI workflow and state-machine tests.
4. **Backward compatibility:** positive. Existing string statuses remain unchanged. SQLite is a
   deliberate no-op. The earlier review-field migration already converted the column to string,
   so this migration also acts as drift repair.
5. **Migration/data risk:** the `ALTER TABLE` can lock/rebuild a large table. `down()` is a safe
   no-op, intentionally preventing truncation into the obsolete enum; rollback is not symmetric.
6. **Failure modes:** insufficient DDL privileges, table lock timeout, disk exhaustion, or an
   unsupported MariaDB mode. No record rewrite is performed.
7. **Tests:** zero-to-current MariaDB migration passed; realistic upgrade preserved all 17
   statuses and IDs; the full feature suite passed.
8. **Remaining gaps:** no production-size lock-duration rehearsal and no CI upgrade snapshot.
9. **Decision:** **KEEP**. Add deployment preflight/backup and online-DDL planning before a large
   production table, but do not restore the enum.

### 4.2 State machine behavior — PASS with gap / ADJUST

1. **Behavior changed:** transitions are explicit, actor-gated, compare-and-set by previous
   status, and status is no longer mass assignable.
2. **Why:** prevent direct status writes, stale concurrent decisions, backward movement and
   customer/staff/admin privilege crossover.
3. **Files:** `CreditApplicationStateMachine.php`, `CreditApplication.php`,
   `CreditApplicationService.php`, review/appointment services, factories/seeders and
   `StateMachineTest.php`/`SyntheticStatePathTest.php`.
4. **Backward compatibility:** all 17 real statuses have legal paths. Unsupported out-of-order
   customer writes now fail intentionally. The manual staff appointment workflow remains
   reachable from prior appointment/review states.
5. **Migration/data risk:** none beyond the status DDL. Existing unknown status strings become
   terminal and require manual data repair.
6. **Failure modes:** compare-and-set correctly rejects stale writes. However, when `apply()` is
   called without an outer transaction, the status transaction commits before the audit call;
   an audit failure can leave a changed status without its required audit row.
7. **Tests:** legal chains, actor matrix, terminal states, duplicate submit/decision, audit row,
   synthetic path coverage and full review workflows pass.
8. **Remaining gaps:** make state change plus mandatory audit atomic in one transaction, or use a
   transactional outbox. Reassess permissive direct appointment shortcuts so only the scheduling
   service creates their required appointment invariant.
9. **Decision:** **ADJUST**, not revert.

### 4.3 Prevention of skipped workflow stages — PASS / KEEP

1. **Behavior changed:** credit step requires step 1; project requires step 2; the transition
   graph rejects forward jumps.
2. **Why:** prevent incomplete/identity-inconsistent applications from reaching validation.
3. **Files:** `CreditApplicationService.php`, `CreditApplicationStateMachine.php`,
   `CreditApplication.php`, client/credit/project request tests.
4. **Backward compatibility:** clients that followed the UI sequence continue to work. API
   clients that intentionally wrote steps out of order now receive `STEPS_INCOMPLETE`.
5. **Migration/data risk:** none. Existing partially inconsistent rows are not automatically
   changed.
6. **Failure modes:** stale model state is rejected by the state-machine compare-and-set.
7. **Tests:** explicit skip tests for credit/project and illegal jump tests pass; full backend
   suite passes.
8. **Remaining gaps:** authenticated browser E2E was not rerun, so frontend recovery/error copy
   after an out-of-order deep link is not proven.
9. **Decision:** **KEEP**.

### 4.4 Branch isolation and global roles — PASS / KEEP

1. **Behavior changed:** operational roles are deny-by-default without `branch_id`; branch rows
   must match. `admin`, `super_admin`, and security duties remain global as designed.
2. **Why:** eliminate accidental global access caused by a missing branch assignment.
3. **Files:** `StaffUser.php`, `CreditApplication.php`, `StaffMake.php`, staff controllers,
   broadcasting channel classes, `BranchIsolationTest.php`, `AuthorizationMatrixTest.php`.
4. **Backward compatibility:** admins/super-admins retain global behavior even when they have a
   branch value. Security remains global but permission middleware still limits operational
   actions. Unassigned operational staff now correctly see nothing.
5. **Migration/data risk:** no data mutation; existing unassigned operational staff lose broad
   access by design.
6. **Failure modes:** a bad branch assignment denies or misroutes access rather than broadening
   it. Application-scoped notifications filter by branch plus global roles.
7. **Tests:** list/detail/document/activity/report/channel branch tests and global-admin tests
   pass.
8. **Remaining gaps:** no browser E2E for security/admin global access; future roles must be added
   consistently to CLI provisioning and permission tests.
9. **Decision:** **KEEP**.

### 4.5 Suspended customer and staff behavior — PASS with gap / ADJUST

1. **Behavior changed:** every customer API route now rejects banned/non-active users; staff
   middleware already rejects non-active staff. Customer current tokens are deleted on denial.
2. **Why:** suspension must take effect after token issuance, not only at login.
3. **Files:** `EnsureCustomerUser.php`, `EnsureStaffUser.php`, auth controllers, authorization and
   security-center tests.
4. **Backward compatibility:** active users are unchanged. Suspended users lose API access
   immediately on their next request.
5. **Migration/data risk:** none.
6. **Failure modes:** staff denial does not delete the current token; existing Reverb
   subscriptions are not forcibly disconnected when an account is suspended.
7. **Tests:** suspended login, suspended customer route matrix, suspended staff route matrix,
   security suspend/unsuspend and token-revoke tests pass.
8. **Remaining gaps:** revoke all relevant tokens as part of suspension policy and define how
   active WebSocket sessions are terminated/re-authorized.
9. **Decision:** **ADJUST**.

### 4.6 Appointment locking and uniqueness — FAIL / ADJUST

1. **Behavior changed:** automatic proposals lock application then branch, decisions lock
   application then appointment, and `(credit_application_id, attempt_number)` is unique.
2. **Why:** serialize slot selection, prevent duplicate attempt histories and stale double
   decisions.
3. **Files:** `AppointmentSchedulingService.php`, appointment uniqueness migration,
   `Appointment.php`, `Staff/ReportController.php`, appointment/database tests.
4. **Backward compatibility:** automatic approval/customer accept/reject flows remain compatible.
   The uniqueness migration fails safely if legacy duplicate attempts exist.
5. **Migration/data risk:** adding the unique key is blocking and will fail on duplicate legacy
   rows; there is no preflight/remediation migration.
6. **Failure modes:** the manual staff `scheduleAppointment()` path is not transactional, does
   not lock application/branch, calculates `max(attempt)+1` outside a lock, accepts arbitrary
   occupied slots, changes state before appointment creation, and on an invalid transition still
   updates `branch_id`. The new unique key prevents same-application duplicate attempts but does
   not prevent two applications from taking the same active branch/date/time.
7. **Tests:** real MariaDB automatic proposal and decision concurrency passed; SQLite scheduling
   features pass. There is no concurrent test for the manual staff endpoint.
8. **Remaining gaps:** route every appointment mutation through one transactional service with a
   consistent lock order; validate branch/date/time/capacity; add manual-path concurrency and
   deadlock-retry tests; add an upgrade duplicate preflight.
9. **Decision:** **ADJUST urgently**. Do not revert the unique key or automatic locks.

### 4.7 Audit integrity chain — PASS for concurrency, FAIL for complete guarantee / ADJUST

1. **Behavior changed:** protected rows contain HMAC integrity and previous hashes; a singleton
   head is locked for each write; legacy pre-chain rows remain accepted; a scheduled verifier
   checks content, links and head.
2. **Why:** detect mutation/removal/reordering and serialize concurrent audit inserts.
3. **Files:** audit-chain migration, `AuditLogService.php`, `AuditLog.php`, audit commands,
   `routes/console.php`, `AuditIntegrityTest.php`.
4. **Backward compatibility:** legacy rows stay readable and do not fail verification. New rows
   are chained. Rollback removes only integrity metadata, not audit rows.
5. **Migration/data risk:** rollback permanently discards hashes/head. Rotating `APP_KEY` makes
   old rows unverifiable because no audit-key version or keyring is stored.
6. **Failure modes:** missing/deleted head does not make `log()` fail immediately; state changes
   can commit before audit as described above; the application DB account can still update/delete
   both logs and head, so this is tamper-evident, not immutable/external evidence.
7. **Tests:** tamper and legacy tests pass; 20 real concurrent MariaDB inserts produced a valid
   chain with 20 unique hashes.
8. **Remaining gaps:** dedicated versioned audit HMAC key, missing-head fail-closed behavior,
   transactional coupling/outbox, restrictive DB grants, external seal/export, retention and
   restore verification.
9. **Decision:** **ADJUST**, not revert.

### 4.8 ClamAV and malware policy — PASS policy, incomplete errors / ADJUST

1. **Behavior changed:** uploaded temporary files are scanned before storage; infected files are
   rejected and audited; scan outcome is stored. `required` fails closed if scanner is absent or
   returns an error; `optional` preserves local XAMPP uploads with `unavailable` status.
2. **Why:** stop known malware while keeping free local development usable without ClamAV.
3. **Files:** `DocumentSecurity/MalwareScanner.php`, `DocumentController.php`,
   `credit_documents.php`, document model/resource, malware migration and upload tests.
4. **Backward compatibility:** local default remains usable; legacy documents receive
   `unavailable`; download API and private storage contract remain unchanged.
5. **Migration/data risk:** low; three metadata columns and one index. Existing files are not
   retroactively scanned.
6. **Failure modes:** `ProcessTimedOutException`/process start exceptions are not normalized to
   scanner `error`, so required policy may return an uncontrolled 500 rather than the defined
   503. Optional unscanned files remain downloadable by design. No quarantine/rescan queue exists.
7. **Tests:** infected-before-storage and required-unavailable tests pass; normal optional local
   upload/download and orphan compensation tests pass.
8. **Remaining gaps:** real ClamAV integration/EICAR test, timeout/permission/daemon tests,
   quarantine/rescan and explicit download policy for non-clean files.
9. **Decision:** **ADJUST**. Keep optional local mode; require and verify ClamAV in production.

### 4.9 Document upload/download compatibility — PASS / KEEP

1. **Behavior changed:** malware metadata is added while MIME sniffing, UUID storage, private API
   streaming and authorization remain intact.
2. **Why:** add security without replacing the working document contract.
3. **Files:** document controller/storage/security services, model/resource/config/migration,
   customer/staff document tests.
4. **Backward compatibility:** API adds a response field but does not remove existing fields;
   local optional mode permits uploads and legacy downloads.
5. **Migration/data risk:** old rows default to `unavailable`; no file path rewrite.
6. **Failure modes:** a storage success followed by DB/audit failure is compensated by file
   deletion. Disk deletion before soft-delete can still make recovery impossible if DB delete
   later fails.
7. **Tests:** ownership, staff branch access, deleted/missing file, spoofed MIME, unique path,
   failed upload rollback and local upload pass.
8. **Remaining gaps:** no retroactive scan and no real-object-storage integration test.
9. **Decision:** **KEEP**, with the malware adjustments above.

### 4.10 Queue configuration and requirements — PASS technically, FAIL operationally / ADJUST

1. **Behavior changed:** database queue dispatches after commit; `.env.example` defaults to the
   database driver; tests can still select `sync`.
2. **Why:** business transactions should commit before notifications/broadcasts execute.
3. **Files:** `config/queue.php`, `.env.example`, operations docs, health service, queue tests.
4. **Backward compatibility:** `QUEUE_CONNECTION=sync` remains supported and PHPUnit uses it.
   Default local runtime now requires `queue:work` for realtime/extra-channel delivery.
5. **Migration/data risk:** none beyond existing jobs/failed-jobs tables.
6. **Failure modes:** without a worker, persisted in-app rows remain available but realtime/email
   lag indefinitely. The current real local health endpoint shows 17 old jobs.
7. **Tests:** sync test suite passes; worker-offline jobs remained queued in isolated MariaDB.
8. **Remaining gaps:** no worker supervisor/heartbeat, alert escalation, retry/replay acceptance
   test or documented choice to use `sync` for simple local sessions.
9. **Decision:** **ADJUST** operational configuration/runbook; keep `after_commit=true`.

### 4.11 Async notifications — FAIL / ADJUST

1. **Behavior changed:** notification rows persist synchronously; delivery channels dispatch as
   queued jobs; application notifications are branch-scoped and deduplicated.
2. **Why:** retain an in-app source of truth while removing mail/SMS/WebSocket latency from
   requests.
3. **Files:** `NotificationService.php`, `DeliverNotificationJob.php`, channel registry/drivers,
   notification event/config/tests.
4. **Backward compatibility:** API inbox remains available even with workers offline. External
   delivery timing changes from immediate to eventual.
5. **Migration/data risk:** no new migration in this slice; queue growth must be managed.
6. **Failure modes:** `DeliverNotificationJob::handle()` catches every delivery exception and
   returns success. Laravel therefore deletes the job; no retry and no `failed_jobs` record are
   produced. Dispatch failure after row persistence can also leave a row without channel work;
   there is no outbox/reconciler.
7. **Tests:** persistence, dedupe, audience and queued dispatch are covered. Delivery-failure,
   retry and replay are not.
8. **Remaining gaps:** rethrow retryable errors, define attempts/backoff/timeout, idempotent
   provider delivery, permanent-failure state, outbox/reconciler and operational metrics.
9. **Decision:** **ADJUST urgently**.

### 4.12 Async Reverb broadcasts — PASS with gap / ADJUST

1. **Behavior changed:** notification/report events implement `ShouldBroadcast` and dispatch
   after commit instead of broadcasting synchronously.
2. **Why:** Reverb failure must not roll back or delay business persistence.
3. **Files:** `NotificationSent.php`, `ReportMessageSent.php`, report broadcast service,
   queue/broadcast configs and broadcasting tests.
4. **Backward compatibility:** event names/channels/payloads are unchanged; delivery is eventual
   and requires a worker.
5. **Migration/data risk:** none; backlog can grow in the database.
6. **Failure modes:** offline Reverb causes worker retries/failures; a dispatch-time exception is
   logged and swallowed without a later reconciler.
7. **Tests:** channel authorization/payload tests pass. Isolated worker execution moved failed
   broadcasts into `failed_jobs`, proving they did not silently vanish at worker time.
8. **Remaining gaps:** no automatic replay/reconciler, no Reverb authenticated E2E in this review,
   no failed-broadcast alert.
9. **Decision:** **ADJUST** operations/recovery; keep asynchronous events.

### 4.13 Worker failure behavior — FAIL / ADJUST

1. **Behavior changed:** broadcasts and extra notification channels depend on the queue worker.
2. **Why:** decouple external infrastructure from request latency.
3. **Files:** queue config, events/jobs, health service, operations documentation.
4. **Backward compatibility:** synchronous mode remains selectable; database default changes
   expected local behavior.
5. **Migration/data risk:** stalled/failed tables can grow and consume the application database.
6. **Failure modes:** Reverb errors are visible in `failed_jobs`; delivery-channel errors are
   swallowed and incorrectly reported `DONE`; process absence is inferred only after backlog age.
7. **Tests:** isolated worker proved both behaviors; no crash/restart/signal test exists.
8. **Remaining gaps:** supervisor, heartbeat, graceful shutdown/restart test, retry policy,
   dead-letter replay, alerting and idempotency proof.
9. **Decision:** **ADJUST urgently**.

### 4.14 `sessionStorage` migration — FAIL compatibility verification / ADJUST

1. **Behavior changed:** bearer tokens are tab-scoped; one legacy `localStorage` token is moved
   once and deleted. SC role/user are session-scoped; staff/admin display role remains in
   `localStorage`.
2. **Why:** reduce token persistence and exposure after the browser closes.
3. **Files:** token helpers in client/staff/admin/SC, login/OTP/Google pages, API clients/Echo
   helpers, existing E2E specs.
4. **Backward compatibility:** same-tab refresh/login/logout remains structurally compatible and
   every login path calls the new helper. New independent tabs no longer share login; this is an
   intentional security behavior change, but must be communicated. SC migrates only the legacy
   token, not legacy role/user metadata, so an already-logged-in SC session can be inconsistent.
5. **Migration/data risk:** no server data risk; client state can be lost on close/new tab.
6. **Failure modes:** multi-tab users appear logged out; staff/admin role display can be stale;
   E2E retrieves a null token because it still reads `localStorage`.
7. **Tests:** TypeScript/builds and backend auth tests pass. There are no browser/unit tests for
   token migration, refresh, close, logout, multi-tab, Google, staff/admin/SC sessions.
8. **Remaining gaps:** update E2E to `sessionStorage`, migrate/clear all related metadata
   consistently, test each auth flow and document multi-tab behavior. HttpOnly BFF/cookie design
   remains the stronger production target.
9. **Decision:** **ADJUST**, not revert to persistent bearer tokens.

### 4.15 CSP and security headers — PASS smoke, incomplete proof / ADJUST

1. **Behavior changed:** all portals send CSP, frame denial, nosniff, referrer, permissions and
   opener policy headers; API responses receive dedicated security headers.
2. **Why:** reduce XSS/frame/content-type/browser capability exposure.
3. **Files:** four `next.config.ts` files, `ApiSecurityHeadersMiddleware.php`, `bootstrap/app.php`,
   API header tests.
4. **Backward compatibility:** production builds and login/SC rendering pass. Client permits
   API, Reverb, Google Identity, HTTPS images, data/blob images and geolocation; staff/admin
   permit API/Reverb; SC permits API.
5. **Migration/data risk:** none.
6. **Failure modes:** static CSP still uses `unsafe-inline`; malformed public URL environment
   values fail the build; production `upgrade-insecure-requests` requires HTTPS endpoints.
7. **Tests:** all builds pass, port runtime headers are present, sampled pages had no console
   errors. API header tests pass.
8. **Remaining gaps:** Google Identity was not configured, Reverb was offline, and Leaflet/map,
   remote font/image and authenticated API journeys were not exercised under production CSP.
   Add CSP violation reporting and nonce/hash-based scripts before claiming strong XSS control.
9. **Decision:** **ADJUST** and integration-test; do not remove the headers.

### 4.16 CORS for all four portals — PASS / KEEP

1. **Behavior changed:** exact localhost and 127.0.0.1 origins for ports 3000–3003 are allowed;
   SC is no longer omitted.
2. **Why:** all four local portals must reach the same API.
3. **Files:** `backend/config/cors.php`, `.env.example`, local `.env`, API header tests.
4. **Backward compatibility:** existing ports remain allowed; credentialed responses still use
   exact origins, never `*`.
5. **Migration/data risk:** none.
6. **Failure modes:** the regex pattern permits local origins in every environment even if a
   production exact-origin list is narrower. It is redundant with local `.env.example` entries.
7. **Tests:** all eight origin variants returned 204, exact allow-origin and credentials.
8. **Remaining gaps:** condition/remove development regex in production and add deployed-origin
   tests.
9. **Decision:** **KEEP local behavior**, adjust environment conditioning before production.

### 4.17 CI MariaDB configuration — FAIL as sufficient gate / ADJUST

1. **Behavior changed:** CI adds a MariaDB 11.4 service and migration/audit bootstrap job; SC and
   dependency audits are included.
2. **Why:** SQLite cannot expose MariaDB DDL/locking defects.
3. **Files:** `.github/workflows/ci.yml`, `.env.example`, root/frontend package metadata.
4. **Backward compatibility:** existing SQLite suite/frontends remain separate. No production
   data is touched.
5. **Migration/data risk:** none in ephemeral CI.
6. **Failure modes:** MariaDB job runs migrations but not backend tests, upgrade snapshots or
   concurrency tests. `php artisan db:show` may require `performance_schema` privileges (the
   local least-privilege account failed exactly there). Actions use mutable major tags.
7. **Tests:** local MariaDB 10.4 zero/upgrade tests passed; workflow itself was not executed.
8. **Remaining gaps:** run relevant tests on MariaDB, add upgrade/concurrency jobs, isolate
   Playwright, declare `@playwright/test` (currently `npm ls` is empty), then add secret/SBOM and
   static-analysis gates. Current Playwright discovery succeeds only because of an extraneous
   local installation and is not clean-install reproducible.
9. **Decision:** **ADJUST** before relying on CI as a release gate.

### 4.18 Health checks, scheduler and worker requirements — FAIL readiness / ADJUST

1. **Behavior changed:** health probes DB/cache/storage/queue/Reverb and returns 503 when a used
   dependency is degraded; scheduler verifies audit daily and prunes failed jobs weekly.
2. **Why:** expose operational dependency failures and automate integrity/retention tasks.
3. **Files:** `HealthCheckService.php`, health controller/routes/tests, `routes/console.php`,
   `.env.example`, production operations documentation.
4. **Backward compatibility:** `/api/health` is unauthenticated and sanitized; normal API routes
   are unaffected. Deployments must now run scheduler and worker processes.
5. **Migration/data risk:** health writes a temporary cache/storage probe; cleanup is attempted.
6. **Failure modes:** queue check uses oldest `available_at`, includes reserved/future jobs and has
   no heartbeat; custom thresholds and Reverb values are read with runtime `env()` rather than
   configuration, so config-cached deployments can ignore `.env` customizations. `onOneServer`
   requires a functioning shared atomic cache lock.
7. **Tests:** health success/failure sanitization and stalled queue pass; `schedule:list` shows
   both jobs. Live health returned 503 for 17 old jobs and unavailable Reverb.
8. **Remaining gaps:** move all environment reads into config, add worker/scheduler heartbeat,
   failed-job count and reserved-job-aware age, test config cache and shared lock behavior, and
   supervise `queue:work`, `schedule:work` and Reverb.
9. **Decision:** **ADJUST urgently**.

### 4.19 Secrets and environment changes — PASS with cleanup / ADJUST

1. **Behavior changed:** `.env.example` documents database queue, four CORS portals, optional
   ClamAV, health thresholds and safe local document verification.
2. **Why:** make required services/policies explicit without paid infrastructure.
3. **Files:** `backend/.env.example`, local untracked `backend/.env`, config files and runbooks.
4. **Backward compatibility:** local XAMPP settings remain valid on port 3306. Production must
   override local/debug/optional scanner values.
5. **Migration/data risk:** none.
6. **Failure modes:** local `.env` contains `CORS_ALLOWED_ORIGINS` twice (12-origin and 8-origin
   variants), making precedence ambiguous. `APP_ENV=local` and `APP_DEBUG=true` are appropriate
   locally but unsafe if copied to production.
7. **Tests:** tracked-file scan found no populated secret pattern or user-specific Windows path;
   only the blank `.env.example` is tracked. Runtime CORS passed.
8. **Remaining gaps:** remove the duplicate local key manually during the correction phase,
   provide a production environment checklist, validate required production values at startup,
   and use secret scanning in CI.
9. **Decision:** **ADJUST local configuration**, no secret-driven revert required.

## 5. Regressions and risky changes found

### Confirmed regressions/defects

1. Manual staff appointment scheduling bypasses the new concurrency protocol.
2. Notification delivery failures are swallowed and marked successful.
3. Playwright's authorization assertion reads a token from the old storage location.
4. Playwright assumes three appointment attempts while `Appointment::MAX_ATTEMPTS` is five.
5. Root Playwright dependency is missing from package metadata; clean CI execution is not
   reproducible.
6. SC legacy token migration does not migrate legacy role/user metadata.
7. Health runtime configuration bypasses Laravel config files and is unsafe under config cache.

### High-risk but not yet observed as a regression

- State change and mandatory audit are not always one atomic transaction.
- Audit key rotation has no version/keyring plan.
- Unique appointment migration has no duplicate-data preflight.
- Optional scan makes unscanned documents usable; acceptable locally, unacceptable as an
  undocumented production setting.
- Local-origin CORS regex is active regardless of environment.
- Static CSP has no configured Google/Reverb/map authenticated integration test.

## 6. Missing tests

1. Concurrent manual `scheduleAppointment()` calls across same/different applications.
2. Deadlock retry/timeout tests for all appointment lock orders.
3. Audit failure during a state transition and audit-head deletion.
4. Audit verification across HMAC key rotation.
5. Real ClamAV clean/EICAR/error/timeout/permission integration.
6. Delivery-channel retry, permanent failure, idempotency and replay.
7. Worker crash/restart, scheduler heartbeat and Reverb recovery.
8. Browser tests for legacy token migration, refresh, close, multi-tab and logout.
9. Google, staff, admin and security login persistence under `sessionStorage`.
10. Production CSP journeys for Google Identity, Leaflet tiles, Reverb, images/fonts and API.
11. MariaDB backend suite, upgrade snapshot and concurrency checks in CI.
12. Fully isolated four-portal + SC E2E bootstrap with synthetic credentials/database.

## 7. Required fixes before continuing

These are recommendations only; none were implemented during this review.

### Blocking

1. Route the manual staff appointment endpoint through the same transactional scheduling service
   and lock protocol; add a branch/date/time capacity invariant and concurrency tests.
2. Make notification delivery failures retryable/observable; do not swallow retryable provider
   exceptions. Add idempotent delivery state and a reconciliation path.
3. Repair and isolate Playwright: declare the dependency, use `sessionStorage`, derive attempt
   count from the domain/API, provision an ephemeral database, and include all four portals/SC as
   appropriate.
4. Move health/Reverb/queue thresholds into config; add real worker/scheduler heartbeats and
   failed-job alerts.

### Required before production, not necessarily before the next correction batch

5. Couple state transitions and mandatory audit atomically or through a transactional outbox.
6. Add audit-key versioning/rotation, missing-head fail-closed behavior and restricted/external
   evidence storage.
7. Normalize scanner process exceptions; prove required-mode behavior with real ClamAV and define
   quarantine/rescan/download policy.
8. Complete authenticated browser tests for session migration, CSP, Google and Reverb.
9. Add duplicate-attempt migration preflight and deployment backup/restore rehearsal.
10. Remove duplicate local CORS key and condition development CORS patterns by environment.

## 8. Revert recommendations

- **No complete change should be reverted.** The status migration, deny-by-default branch scope,
  stage ordering, uniqueness constraint, hash-chain serialization, scan metadata, queued
  after-commit broadcasts, session-scoped token direction, security headers and four-portal CORS
  all improve the baseline.
- Do **not** revert to the incomplete enum, unassigned-staff global access, synchronous Reverb, or
  persistent `localStorage` bearer tokens.
- Correct the narrow defects through an **ADJUST** batch with dedicated regression tests.

## 9. Final recommendation

**NOT SAFE TO CONTINUE.**

Stop new feature work until the four blocking items in section 7 are fixed and rerun through:

- the complete backend suite;
- zero and upgrade MariaDB migrations;
- automatic and manual appointment/audit concurrency tests;
- queue failure/retry tests;
- clean-install Playwright E2E covering authentication, workflow, Reverb and all four portals.

After those checks pass without hidden failures, repeat this review and then decide whether the
system is safe to continue hardening. This conclusion does not claim BTS Bank is certified or
ready to process real banking funds.
