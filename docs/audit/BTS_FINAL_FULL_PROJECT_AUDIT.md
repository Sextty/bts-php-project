# BTS Bank — Final Full Project Audit

**Audit date:** 2026-08-24  
**Repository:** `C:\Users\wassi\Desktop\bts-php-project`  
**Audited revision:** working tree based on commit `9d4e...` dated 2026-08-21  
**Scope:** backend, client, staff, admin, security center, E2E, tools, scripts, documentation, database migrations, CI, operations, synthetic data, analytics, and load testing  
**Method:** source inspection plus local execution against SQLite, MariaDB 10.4.32, four Next.js portals, Reverb, queue-backed workflows, and isolated Playwright data  

## Executive conclusion

The project is a capable **bank-credit platform and core-banking pilot**, not a deployable full bank system. Its strongest areas are migration compatibility, deterministic credit state handling, appointment concurrency controls, synthetic data safety, analytics correctness, four-portal build quality, and the breadth of automated tests. Fresh and realistic upgrade migrations passed without losing the sampled legacy users or credit applications. All backend tests, frontend gates, SC tests, dependency audits, targeted MariaDB concurrency tests, and the isolated end-to-end suite passed.

The audit nevertheless found authorization and operations defects that must be corrected before production use. Most importantly, ordinary branch staff can schedule or confirm appointments directly from `STAFF_APPROVED`, bypassing final admin approval; the shared staff Reverb channel can disclose branch-specific notifications to staff in other branches; security-role users can invoke credit-report mutation endpoints; and the banned-customer listing exposes global customer PII to branch staff. File-storage failures can also be committed as successful document uploads. These are confirmed source-level defects even though the normal happy-path E2E suite passes.

No finding reached the threshold for an immediately exploitable unauthenticated compromise, unrecoverable migration loss, or demonstrated ledger imbalance; therefore the final verdict is not “critical failure.” There are, however, multiple P1 corrections and bank-grade controls still missing.

> **FINAL AUDIT PASSED WITH CORRECTIONS REQUIRED**

### Finding totals

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 13 |
| Medium | 16 |
| Low | 5 |
| Informational | 2 |
| **Total** | **36** |

### Immediate decision

- **Demo readiness:** Yes, with isolated synthetic data and documented local dependencies.
- **Production-software readiness:** No, until the P1 authorization, storage consistency, release, and production-policy findings are corrected and retested.
- **Real-bank readiness:** No. MFA, independent immutable audit retention, HA/DR evidence, formal security assurance, reconciliation, operational segregation, and regulatory governance remain mandatory.

## Audit boundaries and evidence rules

This report evaluates the current source tree, including uncommitted and untracked files. Previous phase reports were used only to locate components and claimed evidence; a previous report was not treated as proof when the current implementation could be executed or inspected. No application code, configuration, migration, or runtime data was changed by this audit. The only created file is this report.

The audit did not perform an external penetration test, legal compliance certification, production infrastructure inspection, third-party provider test with real credentials, or a multi-node failure drill. Gitleaks was not installed locally; the current CI definition includes a checksum-pinned Gitleaks step, but the remote workflow was not executed during this audit. A focused repository scan found no obvious committed server secrets, but this is not equivalent to a successful secret-scanner run.

## System architecture map

```text
Browser portals
  client :3000       staff :3001       admin :3002       SC :3003
        \                 |                 |                /
         \---------------- Laravel JSON API :8000 ---------/
                            |       |        |
                      Sanctum   Reverb   database queue/outbox
                            |       |        |
                         MariaDB  WebSocket  workers/scheduler
                            |
          credit workflow, documents, appointments, reports,
          notifications, audit chain, analytics, security telemetry,
          and pilot double-entry banking ledger

Optional external dependencies:
  Google OAuth, Gemini document analysis, ClamAV, email/SMS providers,
  S3-compatible private document storage, and osquery/native host tools.
```

The architecture is a modular Laravel monolith with four separately built Next.js portals. MariaDB is the authoritative transactional store. Reverb supplies private-channel real-time delivery, while a database queue and outbox supply durable asynchronous work. The basic shape is appropriate for the current scale and is easier to operate than premature microservices. The main architectural costs are duplicated portal authentication/API/UI code, very large services/pages, and controls that are enforced only at the application layer.

### Repository inventory

| Area | Observed inventory |
|---|---:|
| Backend files | 377 |
| Laravel migrations | 51 |
| Eloquent models | 21 |
| Controllers | 31 |
| Middleware | 6 |
| Services | 60 |
| Jobs | 5 |
| Events | 2 |
| Artisan commands | 12 |
| Backend test files | 64 |
| API routes | 104, with no duplicate method/URI pair found |
| Client portal files | 89 |
| Staff portal files | 60 |
| Admin portal files | 67 |
| Security-center files | 36 |
| E2E files | 10 |

## Validation results

| Validation | Result | Evidence/result |
|---|---|---|
| Full Laravel suite | PASS | 427 passed, 3 MariaDB-only skipped under the normal runner, 3,126 assertions, 67.54 seconds |
| MariaDB concurrency suite | PASS | 3 tests, 57 assertions; appointment race/capacity, next-day scheduling, and audit-chain concurrent writes |
| MariaDB fresh migration | PASS | All 51 migrations applied to an isolated MariaDB 10.4.32 database |
| MariaDB realistic upgrade migration | PASS | A pre-change SQL snapshot upgraded in an isolated database; 1,020 users and 1,327 credit applications were preserved; all 51 migrations completed |
| Client lint / TypeScript / production build | PASS | All three gates passed |
| Staff lint / TypeScript / production build | PASS | All three gates passed |
| Admin lint / TypeScript / production build | PASS | All three gates passed |
| SC lint / TypeScript / Vitest / production build | PASS | 8 of 8 Vitest tests passed; all other gates passed |
| Isolated Playwright E2E | PASS | 10 of 10 tests in 3.7 minutes against disposable MariaDB and all portals/services |
| Composer validation and audit | PASS | Strict manifest validation; 0 known advisories |
| Root and four portal npm production audits | PASS | 0 known production vulnerabilities |
| Laravel Pint | FAIL | Style violations in 47 files; no runtime failure was inferred from this result |
| Gitleaks | NOT RUN | Local binary unavailable; CI configuration is present but was not executed remotely |

The Playwright run covered registration/OTP/login, three customer application flows, staff approval/rejection and branch isolation, admin final approval and appointments, report chat, SC refresh/tab/logout/suspension, and analytics branch isolation. It did not cover real Google OAuth, real Gemini/ClamAV/providers, hostile WebSocket clients, browser security exploitation, or production failover.

## Business workflow review

The state machine defines 17 application statuses and centralizes transitions, actor checks, row locking, and audit writes. Normal customer steps are policy-guarded; submitted applications are staff-reviewed; admin approval, appointment proposals, customer appointment decisions, cancellation, and terminal rejection paths are represented and tested. The application-number counter and appointment allocation use database locking. All legitimate statuses tested by the suite remain reachable through at least one valid path.

The major exception is the transition surface from `STAFF_APPROVED`: the state machine and staff report route allow a staff actor to move directly to an appointment state. That contradicts the intended staff-then-admin review sequence and makes the existence of the admin decision optional for a direct API caller. See F-001.

## Authorization matrix

“Branch” means restricted to the actor's assigned branch; “global” means deliberately cross-branch. “Gap” identifies current behavior that conflicts with the intended role boundary.

| Resource/action | Customer | Staff/Credit Officer | Admin | Security |
|---|---|---|---|---|
| Credit application read | Own | Branch | Global | Global read |
| Customer application edit/submit/cancel | Own, state-limited | No | Administrative decisions | No |
| Staff review | No | Branch | Global through role inheritance | No intended permission |
| Final approval/rejection | No | No | Global | No |
| Documents | Own application | Branch application | Global | Security metadata/inspection surfaces |
| Appointment accept/reject | Own proposal | No | Administrative management | No intended access |
| Appointment create/manage | No | Branch, but currently bypasses admin stage | Global | **Gap: callable through report permission** |
| Report chat | Own, open lifecycle | Branch | Global | **Gap: global mutation callable** |
| Banned-user list | No | **Gap: global PII** | Global | Global security function |
| Customer suspension/token revocation | No | Ban route available through report capability | Global | Global security function |
| Audit/security telemetry | No | No | Global | Global |
| Analytics/export | No | Branch aggregates | Global | Audit/security export only |
| Pilot banking read | Own accounts/transfers | Authorized branch/senior role | Global | No |
| Pilot banking approval | No | Maker role where granted | Checker/admin | No |

Suspended customer and staff middleware revokes the current token and blocks protected routes. Staff branch isolation is correctly implemented for the main credit-application queries and reports in normal paths. Superuser/admin and security roles are intentionally global. The defects are inconsistent endpoint permissions around that otherwise sound base.

## Findings

### F-001 — Staff can bypass final admin approval and schedule appointments

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Credit workflow, appointments, authorization
- **Evidence:** `backend/app/Services/CreditApplicationStateMachine.php` maps `STAFF_APPROVED` directly to `APPOINTMENT_PROPOSED` and `APPOINTMENT_CONFIRMED` for staff actors. `backend/routes/api.php` exposes `POST staff/reports/{application}/appointment` with only `reports.view`. `backend/app/Http/Controllers/Staff/ReportController.php::scheduleAppointment()` calls `backend/app/Services/AppointmentSchedulingService.php::scheduleManual()` without requiring the application to have reached `APPROVED`.
- **Impact:** A branch staff member can make the admin decision stage optional and directly confirm a customer appointment. The application may have no valid admin decision actor despite appearing later in the workflow.
- **Reproduction:** Submit an application, approve it as ordinary branch staff so it reaches `STAFF_APPROVED`, then call the staff appointment endpoint with a valid branch, slot, and `confirm=true`. The service accepts the state transition.
- **Required correction:** Require `APPROVED` for manual staff/admin appointment creation, or explicitly restrict any exceptional path to a dedicated admin capability with a recorded reason. Remove staff appointment targets from `STAFF_APPROVED`; add negative API and state-machine tests.

### F-002 — Shared staff Reverb channel leaks branch-scoped notifications

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Realtime events, branch isolation
- **Evidence:** `backend/app/Services/NotificationService.php::notifyStaff()` persists notifications for branch-specific recipients, but `backend/app/Events/NotificationSent.php::broadcastOn()` sends non-admin staff notifications to `private-staff`. `backend/app/Broadcasting/StaffChannel.php` authorizes every active staff user. The event payload includes notification content and application metadata.
- **Impact:** An authenticated staff user can subscribe directly and receive notifications intended for another branch. Duplicate broadcasts may also occur because per-recipient records share one audience channel.
- **Reproduction:** Authenticate two active staff users from different branches, subscribe both to `private-staff`, and cause a branch-specific notification. Both authorized subscribers can receive the event.
- **Required correction:** Broadcast to typed per-user channels or branch channels whose authorization checks the staff branch and global-role rules. Test hostile cross-branch subscriptions and event payload isolation.

### F-003 — Security role can mutate credit reports and appointments

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Role authorization
- **Evidence:** `backend/app/Auth/PermissionRegistry.php` grants security users `reports.view`. Multiple mutation routes in `backend/routes/api.php`—report message creation, close/reopen, manual appointment scheduling, and customer banning—require only that read-named capability. The staff route group accepts any active `StaffUser`, and `backend/app/Http/Controllers/Staff/ReportController.php` does not enforce an operational credit role.
- **Impact:** A global security user can alter credit-case communications and workflow state across every branch, violating least privilege and separation of duties.
- **Reproduction:** Log in as a security-role user and POST to one of the staff report mutation endpoints for an application in any branch.
- **Required correction:** Introduce action-specific permissions such as `reports.message`, `reports.manage`, and `appointments.manage`; deny them to security by default. Keep security read access only where explicitly required.

### F-004 — Branch staff can list banned customers from all branches

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** PII access, branch isolation
- **Evidence:** `GET staff/banned-users` in `backend/routes/api.php` requires only `reports.view`. `backend/app/Http/Controllers/Staff/UserBanController.php::index()` queries all banned/suspended users and returns name, email, phone, reason, actor, and application counts without a branch predicate.
- **Impact:** Ordinary branch staff can enumerate sensitive customer information outside their assigned branch.
- **Reproduction:** Ban customers attached to two different branches, authenticate a normal staff user in one branch, and request the banned-user endpoint.
- **Required correction:** Scope normal staff to customers/applications in their branch, or restrict the endpoint to explicit global security/admin permissions. Add response-level branch isolation tests.

### F-005 — Document persistence can report success after failed storage writes

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Documents, storage integrity
- **Evidence:** `backend/app/Services/DocumentStorage/BaseDocumentStorage.php::store()` ignores the boolean returned by `Storage::put()`. The documents filesystem has non-throwing behavior. Deletion removes the object before the database soft-delete/audit sequence, without a compensating transaction across the two systems.
- **Impact:** A database document can be created for a missing object; later validation or download fails. Conversely, a file can be removed while its live database row remains if a later database/audit operation fails.
- **Reproduction:** Configure a writable-looking filesystem adapter that returns `false`, upload a document, or inject a database failure after object deletion.
- **Required correction:** Treat a false write/delete as failure, verify object existence/size, order deletion safely, and use compensating actions plus reconciliation. Add fault-injection tests for storage and database failures.

### F-006 — Production safety requirements are configuration footguns

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Production configuration, health checks, malware policy
- **Evidence:** `backend/.env.example` contains `DOCUMENT_MALWARE_SCAN=optional`, `QUEUE_WORKER_HEALTH_REQUIRED=false`, `SCHEDULER_HEALTH_REQUIRED=false`, and development-oriented values. Copying it into production overrides safer environment-sensitive defaults. Readiness does not require the malware scanner. `docs/PRODUCTION_OPERATIONS.md` recommends required scanning but does not enforce a startup gate.
- **Impact:** A nominally ready production instance can accept uploads without malware scanning and report ready while queue workers or the scheduler are offline. Durable outbox work then accumulates and notifications/broadcasts are delayed.
- **Reproduction:** Run with `APP_ENV=production` and the example values, stop ClamAV/workers/scheduler, then inspect readiness and upload behavior.
- **Required correction:** Add a production preflight that rejects unsafe combinations, split development and production examples, and require scanner/worker/scheduler health by policy in production.

### F-007 — Security telemetry labels inferred or fabricated data as real

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Security Center, osquery/device telemetry
- **Evidence:** `backend/app/Services/DeviceDetectorService.php` derives a Tunisian location from `crc32($ip)`, creates deterministic fallback adapters/MAC addresses, and may use server-host attributes to label a browser device. It does not consume the frontend's device fingerprint header. `backend/app/Services/Osquery/OsqueryEngine.php` states “ZERO FAKE DATA” while fallback paths synthesize hardware identifiers and expose application records as host-like telemetry.
- **Impact:** Analysts may treat invented location, network, hardware, or host data as incident evidence, causing false positives, false attribution, and poor audit credibility.
- **Reproduction:** Run without native osquery/network-adapter data and inspect the SC device/network results and engine mode.
- **Required correction:** Label every datum with source and confidence; return `unknown` instead of invented values; clearly separate application telemetry from host telemetry; consume a defined, privacy-reviewed client identifier only if required.

### F-008 — Development database backup is unignored inside the repository

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Data protection, repository hygiene
- **Evidence:** `backups/bts_php_backend-before-aria-repair-20260823-105742.sql` is approximately 5.3 MB, untracked, and `git check-ignore` reports it is not ignored.
- **Impact:** A database dump containing development records, credential hashes, workflow, and audit data can be accidentally committed, uploaded, or included in an archive.
- **Reproduction:** Run `git status --short` and `git check-ignore` for the backup path.
- **Required correction:** Move dumps outside the repository, ignore the directory/pattern, encrypt retained backups, restrict ACLs, and scan history before release. Do not commit the dump.

### F-009 — The audited implementation is not release-reproducible

- **Severity / confidence / priority:** HIGH / CONFIRMED / P1
- **Component:** Source control, rollback, release assurance
- **Evidence:** The working tree contains 341 modified/untracked status entries. The tracked diff alone spans 222 files with 6,226 insertions and 2,490 deletions; major Phase 0–6 additions are untracked. Nested `admin/admin/.next` and `staff/staff/.next` output trees and several `desktop.ini` files are also untracked.
- **Impact:** A deployment or clone from the current commit does not reproduce the tested system. Review, rollback, provenance, and incident reconstruction are unreliable.
- **Reproduction:** Compare `git status --short`, `git diff --stat`, and a clean checkout of the current commit.
- **Required correction:** Curate generated artifacts, split the implementation into reviewed commits, rerun all gates on the exact commit, and produce a signed/reviewed release artifact.

### F-010 — Privileged portals lack multi-factor authentication

- **Severity / confidence / priority:** HIGH / HARDENING OPPORTUNITY / P1
- **Component:** Staff/admin/security authentication
- **Evidence:** Staff, admin, and security logins are password-based Sanctum sessions. Customer OTP controls do not provide MFA for privileged employees. Existing operations documentation treats stronger privileged identity controls as future work.
- **Impact:** A stolen privileged password grants the role's full branch or global authority, including customer PII and security operations.
- **Reproduction:** Authenticate to each privileged portal with only username/email and password.
- **Required correction:** Require phishing-resistant MFA for admin/security and at least TOTP/WebAuthn for staff, with recovery governance, step-up authentication for critical actions, and session/device policy.

### F-011 — Audit chain is not independently immutable

- **Severity / confidence / priority:** HIGH / HARDENING OPPORTUNITY / P1
- **Component:** Audit integrity
- **Evidence:** `backend/app/Services/AuditLogService.php` uses an HMAC chain and a locked global chain head. Eloquent mutation guards prevent routine model edits, and concurrency tests pass. However, a database administrator or query builder can modify both log rows and chain head; legacy rows remain unchained; no WORM/off-box anchor is present.
- **Impact:** The chain detects many accidental/application changes but is not strong non-repudiation against a privileged database or application-key compromise.
- **Reproduction:** With direct database access, alter a log and recompute downstream HMAC values using the application key.
- **Required correction:** Export signed hashes/logs to independently controlled immutable storage or SIEM, rotate keys under formal custody, chain/anchor legacy boundaries, and alert on verification failure.

### F-012 — Core banking module remains a pilot, not a bank-grade ledger platform

- **Severity / confidence / priority:** HIGH / HARDENING OPPORTUNITY / P1
- **Component:** Banking ledger and operations
- **Evidence:** The `/v1/banking` API implements accounts, millime-denominated double entry, idempotency, stable account locking, and maker-checker transfers. No portal currently consumes it. Cash deposits can be single-admin actions; balanced posting and immutability are service/Eloquent rules rather than database constraints; reconciliation, holds, reversals, settlement, product accounting, and bounded statements are incomplete.
- **Impact:** The module is valuable for controlled demonstrations and further development, but cannot serve as the authoritative ledger for real funds.
- **Reproduction:** Inspect banking routes/services and portal API usage; issue a permitted admin deposit and observe the absence of a second approver and reconciliation workflow.
- **Required correction:** Define the regulated ledger boundary, accounting controls, dual control, reconciliation, reversals, limits, end-of-day, immutable journals, disaster recovery, and independent assurance before any real-money use.

### F-013 — AI document authenticity is advisory and fail-open

- **Severity / confidence / priority:** HIGH / HARDENING OPPORTUNITY / P1
- **Component:** Document verification, fraud controls
- **Evidence:** `backend/app/Services/DocumentVerificationService.php` and Gemini client/validator paths record unavailable, malformed, or failed AI analysis without universally blocking the credit workflow; only an explicit invalid verdict is blocking. Local development commonly has no effective authenticity provider. ClamAV checks malware, not authenticity.
- **Impact:** A syntactically valid fake PDF can pass the upload and workflow gates when AI is unavailable or inconclusive. Users may incorrectly interpret “AI verification” as proof of authenticity.
- **Reproduction:** Upload a safe PDF containing fabricated financial content while Gemini is disabled/unavailable, then continue validation.
- **Required correction:** Describe AI as advisory, require deterministic document/business checks and human review for material decisions, define fail-closed policies by document/risk tier, and monitor provider failure. Do not rely on a generative-model verdict as sole fraud control.

### F-014 — Branch overview exposes cross-branch operational metrics

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** Branch isolation
- **Evidence:** `GET staff/branches/overview` requires only `reports.view`; the appointment management controller returns all branches and global appointment/capacity statistics without scoping normal staff.
- **Impact:** Staff can inspect operational volumes and capacity outside their branch.
- **Reproduction:** Request the endpoint as a normal branch staff user.
- **Required correction:** Scope normal staff to their branch and reserve global overview for explicit global permissions.

### F-015 — Google authentication issues a token to a suspended customer

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** Authentication, suspension policy
- **Evidence:** The Google auth controller/session issuance path does not reject suspended/banned users before token creation. Customer middleware blocks protected routes and revokes the token on first use.
- **Impact:** Protected data remains blocked, but token issuance and login audit semantics contradict the suspension policy and create a needless active credential.
- **Reproduction:** Suspend an existing Google-linked customer, complete Google login, and observe token issuance followed by rejection on a protected route.
- **Required correction:** Check active status atomically immediately before every token issue, including post-OTP and Google paths; test suspension between pre-auth and OTP completion.

### F-016 — Report lifecycle can be opened or changed outside the locked workflow

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** Report chat, workflow
- **Evidence:** Staff `closeThread()` and `reopenThread()` do not consistently assert the application is locked or already in the corresponding report state. A first message can make `CreditApplication::isReportOpen()` true even on an otherwise inappropriate application state.
- **Impact:** Direct API calls can create report activity and lifecycle changes before the intended credit-review stage.
- **Reproduction:** Call close/reopen/message endpoints against a draft or pre-report application with a globally authorized role.
- **Required correction:** Centralize a report state machine, enforce valid application/report states on every mutation, and audit close/reopen explicitly.

### F-017 — Concurrent customer messages can bypass the turn-taking rule

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P2
- **Component:** Report chat concurrency
- **Evidence:** The customer report controller checks the latest sender before the write transaction and does not lock the application/message stream. Two requests can both observe a staff message as latest and then both insert customer messages.
- **Impact:** The “wait for staff reply” business rule is race-prone, creating inconsistent conversations.
- **Reproduction:** Send two parallel customer message requests immediately after one staff reply.
- **Required correction:** Serialize on the application/report row within a retrying transaction and recheck the latest message after acquiring the lock; add a MariaDB race test.

### F-018 — Positive financing total can have a zero component breakdown

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** Credit-request validation, analytics quality
- **Evidence:** Credit validation compares the component sum only when that sum is greater than zero. A positive requested total with all FDR/AMG/CHP/EQP/EPR components zero is accepted, while analytics later flags the same record as a data-quality violation.
- **Impact:** The workflow admits financially incomplete applications and contaminates dashboards.
- **Reproduction:** Submit a positive global amount with every component equal to zero.
- **Required correction:** Require an exact positive breakdown whenever the requested amount is positive and reuse the same invariant in API, domain service, generator, and analytics tests.

### F-019 — Financing components are not linked to required document categories server-side

- **Severity / confidence / priority:** MEDIUM / LIKELY / P2
- **Component:** Documents, business policy
- **Evidence:** `backend/config/credit_documents.php` marks categories optional, while validation can be satisfied by any accepted document. The portal conditionally displays category uploads for financing components, but a direct API client can submit an unrelated document.
- **Impact:** If BTS rules require evidence for non-zero FDR/AMG/CHP/EQP/EPR components, incomplete dossiers can pass.
- **Reproduction:** Set a financing component greater than zero, upload only an unrelated “other” document, and validate.
- **Required correction:** Obtain an authoritative product/legal rule matrix, encode it in one domain service, and test API bypasses. If documents are intentionally optional, make that policy explicit.

### F-020 — Security queries continue when their audit write fails

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** Security audit logging
- **Evidence:** `backend/app/Services/Osquery/OsqueryService.php::logAuditEvent()` catches failures, while query and quick-audit operations still return their results.
- **Impact:** Sensitive security activity can occur without durable audit evidence during database/chain failures.
- **Reproduction:** Inject an audit-log failure and execute a security query.
- **Required correction:** Fail closed for privileged queries or durably enqueue an independently verifiable event before returning results; alert on any audit failure.

### F-021 — Mixed appointment/admin operations have a lock-order deadlock risk

- **Severity / confidence / priority:** MEDIUM / LIKELY / P2
- **Component:** Database concurrency
- **Evidence:** Admin approval paths acquire application and audit-chain locks before appointment branch allocation; manual scheduling acquires application/branch locks before a state-machine audit write. Cross-application operations in one branch can therefore acquire global audit and branch locks in opposite order. Existing tests cover same-operation races, not this mixed cycle.
- **Impact:** Under contention, requests may deadlock or return transient failures even though double booking remains prevented.
- **Reproduction:** Run parallel admin approval/auto-proposal and manual rescheduling on different applications in the same branch while increasing lock duration.
- **Required correction:** Define one global lock order and retry all affected outer transactions; add a targeted mixed-operation MariaDB test.

### F-022 — Notification broadcasts are produced but not consumed by current portals

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P2
- **Component:** Reverb, notifications
- **Evidence:** Portal Echo clients subscribe to report-chat events, but no current portal listener consumes `notification.sent`. Durable notification inbox endpoints remain available.
- **Impact:** Queue/Reverb work is spent without delivering live UI updates; users see notifications only after polling/refresh, and operators may mistake successful broadcasts for visible delivery.
- **Reproduction:** Trigger a notification while a portal is open and observe no live notification listener update.
- **Required correction:** Either add correctly isolated listeners with reconnect/backfill behavior or stop broadcasting this event until a consumer exists.

### F-023 — Account statements are loaded without pagination or a date bound

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** Pilot banking performance
- **Evidence:** The ledger statement service loads the account's entries without a mandatory range or cursor.
- **Impact:** Long-lived/high-volume accounts can exhaust memory, create slow queries, and produce oversized API responses.
- **Reproduction:** Generate a large entry history and request the full statement.
- **Required correction:** Require bounded dates and cursor pagination, add a stable covering index, and cap export jobs separately.

### F-024 — CI omits the strongest system and upgrade gates

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P1
- **Component:** CI/CD
- **Evidence:** `.github/workflows/ci.yml` runs backend SQLite tests, MariaDB migrations/concurrency, synthetic smoke, dependency/SBOM/secret steps, and four frontend gates. It does not run the isolated Playwright suite, a realistic legacy-snapshot upgrade, backup/restore verification, or Pint. No signed deployment/provenance stage is present.
- **Impact:** A change can pass CI while breaking cross-portal behavior, a real upgrade path, restore readiness, or formatting policy.
- **Reproduction:** Review the workflow jobs against the locally executed gates in this report.
- **Required correction:** Add isolated Playwright, curated non-sensitive upgrade fixtures, periodic restore drills, formatting, and controlled release provenance. Keep secrets out of fixtures.

### F-025 — Some down migrations are destructive or unsafe for evolved data

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P2
- **Component:** Database rollback
- **Evidence:** The staff-role down migration narrows a string role back to a staff/admin enum without a preflight for security/future values. Audit-chain rollback drops integrity fields and chain-head data. The credit-status migration intentionally prioritizes safe forward compatibility rather than reversible enum narrowing.
- **Impact:** A rollback can fail, truncate semantic values, or remove audit evidence even though forward upgrades pass.
- **Reproduction:** Insert a security role or chained audit rows, then run the corresponding down migration in an isolated database.
- **Required correction:** Treat these as roll-forward migrations, document irreversible points, create data-preserving compensating migrations, and rehearse rollback/restore separately.

### F-026 — Public readiness probe performs relatively expensive mutable checks

- **Severity / confidence / priority:** MEDIUM / HARDENING OPPORTUNITY / P2
- **Component:** Health endpoints
- **Evidence:** The unauthenticated health/readiness path performs database/cache/storage operations, queue/outbox counts, disk checks, and a Reverb connectivity probe. No endpoint-specific production network restriction was demonstrated.
- **Impact:** Aggressive or hostile polling can amplify database/storage work and disclose operational availability characteristics.
- **Reproduction:** Repeatedly call `/api/health` and inspect queries/storage probes.
- **Required correction:** Separate cheap public liveness from authenticated/network-restricted deep readiness, cache expensive checks briefly, and rate-limit externally reachable probes.

### F-027 — Backup tooling lacks proven bank-grade DR controls

- **Severity / confidence / priority:** MEDIUM / HARDENING OPPORTUNITY / P1
- **Component:** Backup and disaster recovery
- **Evidence:** Scripts use exact database names, transaction-aware dumps, SHA-256 checks, document counts, and isolated restore verification. No evidence proves encrypted off-site copies, immutable retention, separated credentials, scheduled restore drills, multi-site failover, or achieved RPO/RTO.
- **Impact:** A local backup script does not establish recoverability after ransomware, site loss, credential compromise, or correlated storage failure.
- **Reproduction:** Compare operational scripts and documentation with a full disaster scenario and recovery evidence.
- **Required correction:** Define RPO/RTO, encrypted off-site/immutable copies, separated key custody, automated monitoring, and recurring end-to-end restore/failover exercises.

### F-028 — Browser token and CSP posture leaves residual XSS impact

- **Severity / confidence / priority:** MEDIUM / HARDENING OPPORTUNITY / P1
- **Component:** Portal security
- **Evidence:** All portals use tab-scoped `sessionStorage` bearer tokens and remove/migrate legacy `localStorage` tokens. Production CSP still includes `script-src 'unsafe-inline'` and `style-src 'unsafe-inline'`. No current dangerous HTML/eval sink was found, and builds/E2E passed.
- **Impact:** A future same-origin script injection can read bearer tokens; CSP provides less protection than nonce/hash-based policies. Tab-scoped storage improves persistence behavior but is not an HttpOnly boundary.
- **Reproduction:** Inspect portal token modules and `next.config.ts` CSP values.
- **Required correction:** Remove inline script allowance using nonces/hashes, consider an HttpOnly/BFF session architecture for privileged portals, and add CSP/report-only monitoring and XSS tests.

### F-029 — Large duplicated components increase regression risk

- **Severity / confidence / priority:** MEDIUM / CONFIRMED / P2
- **Component:** Maintainability
- **Evidence:** Examples include `OsqueryEngine` at roughly 1,138 lines, admin report/activity pages around 1,084/956 lines, staff report/activity pages around 1,057/909 lines, `SyntheticRecordFactory` around 731 lines, and repeated API/auth/UI modules across four portals.
- **Impact:** Security and workflow fixes must be repeated, review becomes harder, and inconsistent portal behavior becomes more likely.
- **Reproduction:** Inspect file sizes and parallel portal implementations.
- **Required correction:** Extract bounded domain services and a tested internal portal package incrementally; do not rewrite all portals at once.

### F-030 — Banned-user listing has correctness and query-efficiency defects

- **Severity / confidence / priority:** LOW / CONFIRMED / P2
- **Component:** Staff banned-user UI/API
- **Evidence:** The controller reads `numero_piece_identite` while the actual customer field is `numero_pid`, so CIN is reported as missing. OR/search grouping can leave some banned rows unfiltered, and latest/count application lookups create avoidable per-row queries.
- **Impact:** Search results and displayed identity data are misleading, with degraded performance at scale.
- **Reproduction:** Search a mixed set of banned/suspended users and inspect CIN/query count.
- **Required correction:** Use the real field, group predicates explicitly, and replace per-row lookups with scoped aggregates/eager loading.

### F-031 — Appointment management filters and statistics lack strict request validation

- **Severity / confidence / priority:** LOW / CONFIRMED / P2
- **Component:** Appointment administration
- **Evidence:** Some index filters are read directly rather than through a FormRequest; invalid dates may surface as server errors. Selected branch context and displayed aggregate statistics are not always derived from identical scopes.
- **Impact:** Malformed requests can cause poor API behavior, and UI totals may be confusing.
- **Reproduction:** Send invalid date/status filters and compare selected-branch rows with aggregate cards.
- **Required correction:** Validate all filters, normalize dates/timezone, and use a shared scoped query for rows and metrics.

### F-032 — Legacy generic broadcast channel is weakly typed

- **Severity / confidence / priority:** LOW / HARDENING OPPORTUNITY / P3
- **Component:** Broadcasting authorization
- **Evidence:** `backend/routes/channels.php` retains an `App.Models.User.{id}` channel whose authorization compares only a numeric ID and does not check model type/status. Current typed events use other channels.
- **Impact:** If reused later, customer/staff ID collisions or suspended actors could create unintended authorization.
- **Reproduction:** Review the legacy channel closure and current event channel names.
- **Required correction:** Remove unused dead channel code or replace it with a typed, status-aware channel and tests before use.

### F-033 — Documentation and pre-auth token handling have minor safety defects

- **Severity / confidence / priority:** LOW / CONFIRMED / P3
- **Component:** Documentation, authentication UX
- **Evidence:** `docs/PRODUCTION_OPERATIONS.md` documents `SANCTUM_EXPIRATION=60`, while code expects `SANCTUM_TOKEN_EXPIRATION`. Several OTP/Google continuation pages accept a legacy pre-auth token from the query string before moving it into session storage.
- **Impact:** Operators can set an ineffective variable; query tokens may appear in browser history or same-origin logs/referrers.
- **Reproduction:** Follow the documented variable name and inspect pre-auth page query parsing.
- **Required correction:** Fix the variable name and remove query-token fallback after a controlled migration period.

### F-034 — Generated artifacts and formatting debt reduce repository hygiene

- **Severity / confidence / priority:** LOW / CONFIRMED / P3
- **Component:** Code quality
- **Evidence:** Pint reports style issues in 47 files. Nested `.next` output exists under `admin/admin` and `staff/staff`; root/portal `desktop.ini` files are present; `test_key.php` and `test_key2.php` are development artifacts.
- **Impact:** Noise increases review cost and accidental-commit risk, though current builds/tests remain functional.
- **Reproduction:** Run `vendor/bin/pint --test` and inspect untracked files.
- **Required correction:** Curate ignores, remove generated artifacts through an approved cleanup, and enforce formatting in CI after normalizing the baseline.

### F-035 — Current declared dependency set has no known audited advisory

- **Severity / confidence / priority:** INFORMATIONAL / CONFIRMED / P3
- **Component:** Dependencies
- **Evidence:** Composer strict validation/audit and production npm audits for root plus all four portals returned zero known advisories on 2026-08-24.
- **Impact:** Positive current signal only; it does not cover zero-days, unmaintained transitive behavior, dev-only packages, or future advisories.
- **Reproduction:** Run Composer and npm production audits against the current lockfiles.
- **Required correction:** Keep automated scheduled audits, lockfile review, SBOM generation, and timely supported-version updates.

### F-036 — Secret-scanner evidence is incomplete for the current dirty tree

- **Severity / confidence / priority:** INFORMATIONAL / CONFIRMED / P3
- **Component:** Secret scanning
- **Evidence:** Local Gitleaks execution failed because the binary was not installed. CI downloads checksum-pinned Gitleaks 8.30.1, but that remote workflow was not run as part of this audit. Manual patterns found no obvious committed server secret; portal `.env.local` files are ignored and contain only public identifiers observed during inspection.
- **Impact:** Secret absence cannot be certified, particularly for 341 current working-tree entries.
- **Reproduction:** Attempt local Gitleaks execution and inspect CI.
- **Required correction:** Run the pinned scanner over history plus untracked working-tree content before commit/release, then retain the machine-readable result.

## Detailed subsystem assessment

### Database and migrations

Fresh MariaDB migration and a realistic pre-change upgrade both passed. Foreign keys, branch links, decimal precision, coordinate precision, appointment attempt uniqueness, ledger millime integers, and major indexes are generally appropriate. The status conversion to a string avoids MariaDB enum drift and allows every application status currently defined by the model. The tradeoff is that direct SQL can insert an invalid status; the application state machine remains the enforcement boundary.

The tested upgrade preserved 1,020 users and 1,327 applications. This is strong evidence for the supplied snapshot, not a universal proof for every production history. Before production, archive a sanitized schema/data-shape fixture for every supported source version and test forward migration on the exact release artifact.

### Transactions, appointments, and audit chain

Application decisions and state writes use row locks and database transactions. Appointment allocation locks branch/day capacity, enforces weekdays/time slots, supports local Tunis timezone rules, uses an attempt-based uniqueness model, and safely allows released cancelled/rejected slots to be reused. MariaDB race tests passed for same-operation concurrency, capacity, and audit chaining. F-021 identifies the remaining mixed lock-order case.

The audit HMAC chain, redaction, versioned keys, global head lock, and integrity verifier are meaningful controls. Concurrent inserts remained valid in the targeted test. It is still an application-level tamper-evidence mechanism, not an independently immutable record; see F-011 and F-020.

### Authentication and sessions

Customer password/OTP flows hash OTPs, enforce cooldown/attempt limits, use generic error responses, and perform atomic consumption. Sanctum bearer tokens have a configured absolute lifetime. Moving browser credentials from persistent local storage to tab-scoped session storage was successful in builds/E2E; legacy values are removed. Logout revokes the current token, and multi-tab sessions are intentionally independent. Suspended actors are rejected by protected middleware.

The remaining material gaps are pre-issuance suspension checks on Google/post-OTP paths, privileged MFA, and the browser-readable bearer-token/CSP model. Live Google OAuth was not exercised because real provider credentials were out of scope.

### CORS and security headers

The configured development origin set covers all four portals on localhost and 127.0.0.1. Next.js CSP and security headers compile and did not break the tested client/staff/admin/SC flows, report WebSockets, or Leaflet-dependent pages. Production must replace development origins and cannot rely on wildcard host patterns. Google OAuth itself was not live-tested. The retained inline CSP allowances are documented in F-028.

### Documents, malware, and AI verification

Uploads use server-derived UUID paths, MIME inspection, private storage, authorization-checked downloads, attachment disposition, and `nosniff`. Report attachments are bound to authorized report messages. ClamAV required mode fails closed when unavailable; optional mode preserves local development usability. That environment-sensitive behavior is reasonable only if production cannot accidentally remain optional.

ClamAV addresses malware, not document truth. Gemini analysis is latency-sensitive, external, and advisory; malformed/unavailable analysis does not prove a document legitimate. A single document can also consume long synchronous provider time. Human/business validation remains required. Storage consistency failures are covered by F-005.

### Queue, outbox, notifications, and Reverb

Database queues, heartbeats, retries, failed-job visibility, and the durable async outbox improve failure behavior. When workers are offline, durable rows do not silently disappear; they accumulate for later processing. Development can still use synchronous behavior where configured. Production correctness therefore depends on enforcing worker/scheduler health and monitoring age/backlog.

Report-chat private channels are typed and branch-aware. The shared staff notification channel is not branch-safe (F-002), and the current portals do not consume notification broadcasts (F-022). Reverb health currently proves connectivity more than authenticated end-to-end delivery.

### Four portals and UI/UX

Client, staff, admin, and SC all passed lint, TypeScript, and production build. The isolated E2E run proved the main cross-portal workflow at desktop automation level. Accessibility and responsive behavior have reasonable component primitives, but this audit did not perform formal WCAG testing, keyboard-only coverage, screen-reader testing, visual-regression comparison, mobile-device lab testing, or localization review.

Four portal codebases duplicate token, API, Echo, error, and component behavior. This is currently manageable but already creates large files and inconsistent-change risk. Incremental shared packages are justified; a wholesale design rewrite is not.

### Analytics and performance

Analytics are branch-scoped, audited, capped to a 730-day range and 2,500 rows, and avoid raw PII exports. Eight analytics tests and before/after artifacts support result equivalence. The measured medium profile improved from approximately 33.075 seconds to 3.507 seconds—about 89.4%—with 30 queries and roughly 2 MiB memory delta. The supporting index migration took roughly 54 seconds on the medium dataset and may require a maintenance or online-DDL plan at production scale.

The synthetic generator is deterministic, additive, checkpointed, uses reserved `.invalid` identities, creates no real tokens, and applies strong production overrides. Profiles extend from about 1,000 to 1,000,000 customers, but only the medium profile has meaningful local evidence. The strict load tester uses a disposable signed/loopback database and safe cleanup. The highest observed local concurrency was 30; a burst p95 around 6.8 seconds is an engineering measurement, not an acceptable bank SLA. Large/massive profiles, long soak, Reverb subscription load, OS saturation, failover, and storage latency remain unproven.

### Observability and operations

The project has liveness/readiness separation, JSON-capable logging, request IDs, queue heartbeat/outbox metrics, backup/restore scripts, and operational documentation. Production still needs centralized retention, actionable alerting, service-level objectives, on-call procedures, time synchronization, infrastructure metrics, log immutability, and demonstrated recovery. XAMPP/MariaDB on port 3306 is suitable for local development only, not a production bank topology.

## Readiness matrix

| Area | Demo ready | Production software ready | Real bank ready | Blocking reason |
|---|---|---|---|---|
| Architecture | Yes | Conditional | No | Release reproducibility, HA, governance |
| Credit workflow | Yes | No | No | F-001 admin-stage bypass |
| Branch isolation | Yes on happy path | No | No | F-002, F-004, F-014 |
| Authentication | Yes | Conditional | No | Privileged MFA and suspension issuance gaps |
| Authorization | Yes on tested routes | No | No | Security/report mutation permission defects |
| MariaDB schema/migrations | Yes | Conditional | No | Forward tests pass; rollback/online DDL/HA remain |
| Documents | Yes | No | No | Storage consistency and enforced production policy |
| Malware/AI verification | Yes as advisory | Conditional | No | Scanner policy and authenticity assurance |
| Appointments | Yes | Conditional | No | Workflow bypass and mixed lock-order test |
| Audit integrity | Yes | Conditional | No | No independent immutable anchor |
| Queue/outbox | Yes | Conditional | No | Worker/scheduler enforcement and operational proof |
| Realtime | Yes for chat | No | No | Branch notification leak and absent UI consumer |
| Four portals | Yes | Conditional | No | Security hardening, accessibility, release gate |
| Analytics | Yes | Yes with capacity plan | No | Production-scale/online migration evidence |
| Synthetic/load tooling | Yes | Yes for testing | Not applicable | Large/soak evidence incomplete |
| Pilot ledger | Yes as pilot | No | No | Missing bank accounting/operations controls |
| Observability | Yes | Conditional | No | Central monitoring, alerting, SLOs |
| Backup/DR | Yes locally | No | No | Off-site immutable backup and RPO/RTO not proven |
| CI/CD | Yes | No | No | E2E/upgrade/restore/release provenance gaps |

## Scores

Scores represent current evidence, not planned work.

| Area | Score / 10 | Rationale |
|---|---:|---|
| Architecture | 7.5 | Appropriate modular monolith; duplication and release state reduce confidence |
| Backend implementation | 7.4 | Strong domain services/tests; several permission and consistency defects |
| Database design | 7.5 | Fresh/upgrade/concurrency pass; application-only constraints and rollback gaps |
| Business logic | 6.3 | Broad state model, but admin approval can be bypassed |
| Security engineering | 5.8 | Good baseline headers/scanning/audit, major identity/telemetry/ops gaps |
| Authentication | 6.5 | Strong customer OTP; no privileged MFA and one suspended-token issue |
| Authorization | 5.5 | Main branch policies work; multiple global/mutation leaks remain |
| Frontend/UI | 7.2 | Four clean builds and E2E; duplication and formal accessibility gaps |
| Realtime | 5.8 | Chat works; notification isolation/consumption defects |
| Documents | 6.3 | Private authorized storage design; failed-write consistency gap |
| Appointments | 7.7 | Strong locking/race coverage; workflow/lock-order exceptions |
| Analytics | 8.0 | Correct, scoped, measured improvement |
| Performance | 6.8 | Useful medium evidence; no production soak/large validation |
| Observability | 6.5 | Good application signals; no central operational proof |
| Backup/DR | 5.5 | Useful scripts; bank-grade recovery unproven |
| Testing | 7.8 | Broad backend/E2E/concurrency coverage; key negative cases missing |
| CI/CD | 6.0 | Good gates, but strongest local gates absent from CI |
| Maintainability | 5.8 | Large files, portal duplication, dirty release tree |
| **Overall** | **6.7** | Strong advanced prototype; not production-bank ready |

## Prioritized correction backlog

### P0 — Immediate stop-ship

No confirmed P0 finding was identified in the audited environment. Any evidence of real-data leakage from the unignored backup, active secret exposure, ledger imbalance, or unauthenticated access would immediately promote the relevant item to P0.

### P1 — Required before production-software approval

1. Close the `STAFF_APPROVED` appointment bypass and add negative state/API tests.
2. Replace the shared staff notification channel with user/branch-isolated channels.
3. Split report read and mutation permissions; remove credit mutations from security role.
4. Branch-scope or globally restrict banned-user data and branch-overview metrics.
5. Make document storage failures transactional/compensated and fault-tested.
6. Enforce production-required malware scanning, queue worker, and scheduler health.
7. Remove invented security telemetry or label it explicitly as unknown/inferred.
8. Move and protect the database backup; scan repository history and working tree.
9. Produce a clean, reviewed, reproducible release commit and artifact.
10. Add privileged MFA and step-up authentication.
11. Export audit evidence to independent immutable retention; fail closed on security-audit write failure.
12. Block suspended users before every token issuance path.
13. Enforce financing amount/breakdown invariants and decide/document-category policy.
14. Paginate banking statements and define the pilot ledger boundary explicitly.
15. Add Playwright, realistic upgrade, restore, and release-provenance gates to CI.
16. Establish encrypted off-site backups, RPO/RTO, and recurring restore proof.
17. Harden CSP/session design for privileged portals.
18. Define deterministic/human document authenticity controls; keep AI advisory.

### P2 — Next hardening cycle

1. Serialize customer report turn-taking and add a MariaDB race test.
2. Enforce report lifecycle states and explicit close/reopen audit events.
3. Standardize lock order and test mixed appointment/admin operations.
4. Remove unused notification broadcasts or implement isolated portal consumers.
5. Validate appointment filters and align aggregate scopes.
6. Correct banned-user field/search/query behavior.
7. Plan online index migrations and validate large/massive synthetic profiles.
8. Run long-duration application, queue, Reverb, and database soak tests.
9. Refactor oversized services/pages incrementally and share tested portal libraries.
10. Separate public liveness from restricted deep readiness.
11. Create data-preserving roll-forward/rollback runbooks.
12. Add centralized alerts, SLOs, log retention, and failure drills.

### P3 — Hygiene and governance

1. Remove or harden the unused generic broadcast channel.
2. Correct the Sanctum documentation variable and retire query-token fallback.
3. Normalize Pint baseline and enforce formatting.
4. Ignore/remove nested build output and desktop artifacts.
5. Schedule dependency and secret scans; retain results with release evidence.
6. Complete WCAG, keyboard, screen-reader, responsive, and localization review.

## What not to add yet

Do not add microservices, Kubernetes, Kafka, Elasticsearch, a separate warehouse, or a commercial BI platform merely to make the system appear more “bank-like.” None resolves the confirmed authorization or data-integrity defects. Keep the modular monolith and MariaDB while query/load evidence supports them. Add Redis/Valkey, object storage, a metrics stack, replicas, or orchestration only against measured capacity, durability, failover, or operational requirements. First make the current workflow, permissions, release, audit, storage, and recovery boundaries correct and provable.

## Top 10 risks

1. Staff appointment scheduling bypasses final admin approval.
2. Shared staff WebSocket notifications violate branch isolation.
3. Security users can mutate credit reports/appointments through read-named permission gates.
4. Branch staff can enumerate globally banned customer PII.
5. Document storage failures can be persisted as success.
6. Production can run with optional malware scanning and non-required workers/scheduler.
7. Security dashboards present inferred/fabricated telemetry as real evidence.
8. A sensitive database backup is unignored inside the repository.
9. The tested implementation is not reproducible from the current commit.
10. Privileged MFA, immutable off-box audit, and proven DR are absent.

## Final recommendation

The project may continue as a synthetic-data development/demo system. It must not be represented as a production-ready full bank system, and it should not process real customers or real money in its current state. Correct every P1 application defect, clean and commit a reproducible release, rerun the exact validation matrix including Gitleaks and CI, then commission an independent penetration test and operational recovery exercise. Bank deployment additionally requires legal/regulatory review, formal threat/privacy analysis, identity governance, independent audit retention, HA/DR, reconciliation, segregation of duties, and controlled production infrastructure.

**Final verdict:** **FINAL AUDIT PASSED WITH CORRECTIONS REQUIRED**
