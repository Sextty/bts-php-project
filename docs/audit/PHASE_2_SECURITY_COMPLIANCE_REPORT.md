# BTS Bank — Phase 2 Security and Compliance Hardening Report

Date: 23 August 2026  
Scope: Phase 2 only, starting from the validated P0/P1 correction state.

## Decision

**SAFE TO CONTINUE TO PHASE 3.**

This decision means the Phase 2 code changes are compatible with the validated workflows and the
next engineering phase may start. It is not a production-banking certification and does not
authorize real customer data, real funds, or an Internet production launch. The remaining
deployment controls in this report are mandatory before production.

## Implementation checklist

- Harden tab-scoped browser sessions without attempting a partial cookie/BFF migration.
- Revalidate principal type, suspension, permissions, ownership and branch isolation.
- Enforce content-based document validation, malware policy and controlled downloads.
- Remove sensitive data from durable audit payloads and authentication delivery logs.
- Minimize host telemetry and execute osquery without a command shell.
- Add compatible CSP, browser headers, CORS coverage and generic API error handling.
- Add secret, dependency and SBOM checks to CI.
- Re-run backend, MariaDB, four-portal, isolated browser and security validation.

## Executive result

| Area | Result | Summary |
|---|---|---|
| Authentication and session security | PASS WITH DEFERRED ARCHITECTURE | Persistent `localStorage` bearer data is migrated to `sessionStorage`; expiration, logout and suspension revocation are enforced. A browser bearer remains readable by same-origin JavaScript. |
| Authorization and branch isolation | PASS | Customer/staff principal separation, ownership, deny-by-default branch scope, global roles and private channels are covered by regression tests. |
| Sensitive data and logging | PASS | OTPs, message bodies, tokens and secret-shaped audit fields are no longer written to application logs or durable audit payloads. |
| Document security | PASS WITH DEPLOYMENT CONDITION | Content is inspected, infected content is rejected before durable storage, downloads and AI access apply malware policy, and report attachments use the same private controls. Production must set scanning to `required`. |
| API and browser security | PASS | Four portal CSP/header policies, four-origin CORS, sanitized 500 responses, rate limits and WebSocket authorization remain compatible with builds and E2E. |
| Audit integrity | PASS WITH DEPLOYMENT CONDITION | HMAC chain, key versioning, redaction, concurrent inserts, verification and Eloquent immutability pass. Production DB privileges and external sealing remain deployment work. |
| Secrets and supply chain | PASS | Gitleaks, Composer/npm audits, strict Composer validation and SPDX SBOM generation are present. No real secret was detected. |
| Security telemetry | PASS WITH LIMITATION | osquery runs through a bounded process with timeout, row cap and table allow-list; raw SQL and binary paths are not exposed. Host telemetry still runs inside the API process. |
| Regression matrix | PASS | Final SQLite, MariaDB, frontend, Vitest, build, dependency, secret and 9-scenario isolated E2E checks pass. |

## Threat model by flow

| Flow | Protected assets | Main threats | Implemented controls | Residual risk |
|---|---|---|---|---|
| Customer password/OTP login | Password hash, OTP, bearer session, identity | Enumeration, brute force, OTP disclosure, token theft | Generic login responses, OTP expiry/cooldown/attempt cap, encrypted queue jobs, no plaintext OTP logs, 60-minute Sanctum expiry, current-token logout, suspension revocation | Any successful same-origin XSS can read the `sessionStorage` bearer. |
| Google login | Google assertion, linked identity, phone verification | Invalid audience, account-link takeover, unverified identity | Audience and verification checks, existing-account safeguards, phone OTP completion and automated backend tests | No real external Google browser test; CSP keeps required Google origins. |
| Staff/admin/security login | Privileged token, role, branch | Role confusion, unassigned staff global access, suspended account reuse | Separate entrances, principal middleware, permission registry, explicit global roles, unassigned operational staff denied, suspended token deletion | MFA and just-in-time elevation are not implemented. |
| Credit and appointment workflow | Application state, decision, branch, appointment slot | IDOR, skipped state, cross-branch action, double decision | Ownership/policies, state machine, branch scopes, DB transactions, uniqueness and MariaDB concurrency tests | High-contention capacity limits still need Phase 3/5 measurement. |
| Document and report attachment | Identity documents, private files | MIME spoofing, malware, path traversal, unauthorized download, cloud disclosure | Content inspection, generated paths, private disk, ClamAV process wrapper, required-mode fail-closed policy, access checks, legacy path validation, AI access policy | Real ClamAV/EICAR was not executed locally because ClamAV is absent. |
| Notifications and realtime | Messages, audiences, delivery state | Cross-user channel access, silent job loss, secret leakage | Private channel authorization, suspended-user rejection, persisted inbox/delivery state, retries and failures, encrypted auth jobs, isolated E2E worker/Reverb | Provider-side exactly-once delivery and worker supervision depend on deployment. |
| Audit | Security events, actors, history | Mutation, deletion, secret capture, concurrent chain fork | HMAC chain, versioned keys, chain-head lock, redaction, actor fields, verification command, Eloquent update/delete denial | Application DB credentials must be denied UPDATE/DELETE on published audit data; external immutable export is not yet deployed. |
| Security telemetry | Host processes, sockets, platform data | Command injection, excessive disclosure, expensive query, privileged misuse | Symfony Process argument list, executable discovery, timeout, table allow-list, row cap, generic errors, hashed query audit | Collector is not separated from the API and privileged access lacks MFA/JIT. |

## Authentication and session changes

All four browser portals now store bearer tokens and role metadata in `sessionStorage`, remove the
legacy `localStorage` values during one-time migration, and clear both stores on logout. This
preserves refresh behavior but scopes a session to one tab. The Security Center logout performs a
full navigation only after local revocation, avoiding a router/API redirect race found during the
final E2E run.

`/api/user` now accepts customer principals only. Suspended customer and staff middleware deletes
the current token before rejecting access. Suspended customers are also denied private customer,
application and report channels. Sanctum tokens use a detectable `bts_` prefix and retain the
configured 60-minute expiration.

The implementation does not claim that `sessionStorage` prevents XSS theft. A complete migration
requires one same-origin BFF per portal: exchange credentials server-side, issue `__Host-` cookies
with `HttpOnly`, `Secure` and an explicit `SameSite` policy, add Origin/CSRF protection for state
changes, rotate and revoke sessions server-side, then remove browser bearer handling only after
Google, customer, staff, admin, security and multi-tab E2E pass together. Performing only half of
that migration would have broken the four direct-to-API portals and was intentionally deferred.

## Authorization and branch isolation

The existing policy, permission and route structure was retained. The validation matrix proves:

- customers can access only their own applications, reports and documents;
- customer tokens cannot enter staff routes and staff tokens cannot enter customer routes;
- operational staff are restricted to their assigned branch and unassigned staff are denied;
- `admin`, `super_admin` and `security` retain their deliberately global capabilities;
- document, report, appointment, activity and broadcast boundaries follow the same branch rule;
- suspended internal and customer accounts cannot continue through existing tokens.

No route was broadened to compensate for missing scope information.

## Sensitive data protection

`AuditLogService` recursively replaces password, OTP, bearer, cookie, API key, client secret and
reset-token values before canonicalization and hashing. Authentication delivery jobs implement
Laravel job encryption. SMS/email failure logs contain outcome categories or exception classes,
not OTP values, phone numbers, message bodies, credentials or provider responses.

The isolated Playwright environment no longer recovers OTPs from general logs. It enables a
dedicated `e2e` provider that refuses to start outside `APP_ENV=e2e`, writes only to an ephemeral
test channel and deletes the channel after the run.

## Document and attachment security

The upload path no longer trusts the browser MIME type when server content inspection is
inconclusive. Credit documents and report-chat attachments are scanned before entering their
private durable storage. Infected uploads are rejected, audited and never become downloadable.
When scanning is required, unavailable/error results fail closed. Local development remains usable
with `DOCUMENT_MALWARE_SCAN=optional`.

Downloads, staff previews and AI verification invoke the same malware access policy. A new
additive report-message migration records storage disk, scan status, signature and scan time.
Existing report attachments are preserved as `local`/`unavailable`; they remain available in
optional local mode but are blocked when production uses required scanning. Controller failures
after a successful file write compensate by deleting the orphan.

Production must use a private ClamAV socket/process, current signatures and
`DOCUMENT_MALWARE_SCAN=required`. ClamAV was not installed locally, so process failure behavior was
unit tested and infected behavior used deterministic scanner doubles; a real EICAR integration
check remains a deployment gate.

## API, browser and realtime security

Each Next.js portal returns CSP, referrer, MIME-sniffing, frame, permissions and opener policies.
Production removes `unsafe-eval`; `unsafe-inline` remains for compatibility with the current
Next.js rendering and styles. Customer CSP includes Google authentication and map/image needs;
all policies include the configured API and Reverb WebSocket origin. CSP compatibility is proven
by four production builds and the complete browser journey, not by compilation alone.

CORS defaults include ports 3000–3003 for both `localhost` and `127.0.0.1`. Development regexes
are enabled only by the explicit local switch; production must provide exact HTTPS origins and set
the switch false. Bearer API requests do not rely on ambient cookies, so CSRF is not the current
primary control; the documented BFF migration must introduce Origin and CSRF defenses.

Unexpected API exceptions return a generic `INTERNAL_ERROR` envelope even if debug is
misconfigured. Existing validation and domain errors retain their specific safe responses. Private
Reverb authorization was revalidated for ownership, branch access and suspension.

## Audit integrity and key handling

The P0/P1 HMAC chain remains compatible. Phase 2 adds recursive secret redaction and prevents
Eloquent update/delete operations on `AuditLog`. The chain head is locked during insertion, key
versions are stored per row, historical verification retains legacy-key behavior, and concurrent
MariaDB writers produce one valid chain.

This is tamper-evident, not immutable external evidence. Production deployment must use separate
migration and application DB identities, revoke application UPDATE/DELETE/TRUNCATE/DDL privileges
on audit tables, retain every historical HMAC key version in a secret manager, export signed chain
heads to access-controlled append-only storage, and define retention/legal-hold procedures. Those
infrastructure actions cannot be truthfully completed in a local XAMPP repository.

## Security telemetry hardening

Osquery execution no longer uses `shell_exec` or a composed command string. It uses Symfony
Process, a bounded timeout, an output row cap and an allow-list derived from known engine tables.
Status responses omit the binary path. Audit events store only a query SHA-256 and referenced
tables, not raw SQL. Errors expose a generic response and log only an exception class.

Separating the host collector from the API, minimizing the remaining hardware/device inventory and
adding MFA/JIT to privileged telemetry access remain pre-production architecture work.

## Secret and supply-chain controls

CI now checks Composer metadata/advisories, all portal production npm dependencies, complete Git
history with Gitleaks and an SPDX JSON SBOM. Gitleaks 8.30.1 is downloaded from its official
release with an exact SHA-256; Anchore SBOM action v0.24.0 is pinned to a full commit SHA. See the
[Gitleaks releases](https://github.com/gitleaks/gitleaks/releases) and
[Anchore SBOM action](https://github.com/anchore/sbom-action).

Three historical and four current findings were inspected. Every value is a synthetic notification
deduplication or ledger idempotency key, not a credential. Only their exact fingerprints are
ignored in `.gitleaksignore`; no broad rule is disabled. The final history and selected current
source scans report zero leaks. `.env` stays excluded, `.env.example` contains placeholders only,
and no tracked user-specific local path was found.

The unused `twilio/sdk` package and its unbounded dependency were removed. `composer validate
--strict`, `composer audit` and npm audits for root/client/staff/admin/SC are clean. Existing
third-party GitHub actions that predate this batch still use mutable major tags and should be
pinned to commit SHAs in a later CI maintenance change.

## Compliance-oriented control mapping

This is an engineering traceability map, not a legal opinion or certification.

| Control family | Evidence implemented | Remaining evidence needed |
|---|---|---|
| OWASP ASVS V2/V3 | OTP limits, token expiry/revocation, suspension, role and principal boundaries | MFA, privileged reauthentication, complete BFF/cookie design |
| OWASP ASVS V4 | Ownership, branch permissions, deny-by-default tests, private channels | Periodic access reviews and production JIT policy |
| OWASP ASVS V5/V12 | Form validation, content inspection, private paths, malware policy | Real EICAR gate and archive/bomb policy if archives become supported |
| OWASP ASVS V7/V8 | Redacted logs/audit, private documents, controlled downloads | Formal data classification, retention and deletion schedule |
| OWASP ASVS V9/V14 | Exact CORS, CSP/security headers, generic errors, dependency/secret scans | TLS/reverse-proxy proof, DAST and action-SHA completion |
| NIST CSF Protect/Detect | Access controls, malware prevention, audit chain, health checks | Central alerting, incident exercises and immutable external audit storage |
| ISO 27001 control themes | Least privilege, secure development checks, event logging | Approved policies, owners, evidence retention, supplier and risk registers |
| Tunisian privacy/banking governance | Synthetic tests, local-only document verification default, access/audit controls | Counsel/regulator review, lawful basis, notice, retention, residency and BCT-specific approval |

## Validation evidence

### Backend

- Complete SQLite Laravel suite: **400 passed, 2 MariaDB-only skipped, 2,992 assertions**.
- Phase 2 targeted regression after final attachment changes: **40 passed, 126 assertions**.
- Final post-format report/osquery/session/audit regression: **34 passed, 161 assertions**.
- State, authorization, suspension, audit, document, report attachment and queue tests all pass.

### MariaDB/XAMPP

- Fresh migration from zero: **PASS**; every migration including Phase 2 report attachment columns
  applied on the real XAMPP MariaDB service at port 3306.
- Controlled upgrade: **PASS**; a pre-Phase-2 schema with representative user, application and
  report attachment upgraded without row loss; the legacy attachment retained `local` and
  `unavailable` compatibility values.
- Targeted schema/security/document/concurrency suite: **26 passed, 109 assertions**.
- Appointment decision and audit concurrent-write processes: **PASS**.

One attempted all-tests-on-MariaDB run produced 394 passes and 7 failures after subprocess
concurrency tests committed rows outside Laravel's parent transaction. The leftover rows polluted
later tests that assert exact global counts. No failed assertion indicated a broken foreign key,
workflow or security control. CI therefore runs normal MariaDB schema/security tests before the
intentional subprocess concurrency group. This is a test-isolation limitation and is not hidden.

### Frontends

- Client: ESLint, TypeScript and production build **PASS**.
- Staff: ESLint, TypeScript and production build **PASS**.
- Admin: ESLint, TypeScript and production build **PASS**.
- Security Center: ESLint, TypeScript, **8/8 Vitest tests**, production build **PASS**.
- Generated `.next-e2e` output is now ignored by Git and ESLint.

### End-to-end

Final isolated Playwright result: **9/9 Chromium scenarios passed** against a PID-scoped MariaDB
database with a real queue worker and Reverb process. The run covers customer OTP/login and full
application flow, documents, staff decisions, branch isolation, admin global approval,
appointments, live report chat, tab-scoped session/logout, Security Center access and suspended
security-account rejection. The database and ephemeral OTP channel are removed in `finally`.

An earlier rerun exposed one Security Center logout URL race (8 passed, 1 failed). The browser was
already showing the login screen but remained on the root URL. Logout now performs one full
post-revocation navigation; the final 9/9 run proves the correction.

### Dependency, secret and metadata checks

- Composer audit: **0 advisories**.
- Composer validate strict: **PASS**.
- Root/client/staff/admin/SC npm audits: **0 reported vulnerabilities**.
- Gitleaks full history and selected current sources: **0 unignored leaks**.
- Tracked local-path and real `.env` check: **PASS**.
- `git diff --check`: **PASS**.

The GitHub Actions workflow was reviewed but was not executed by GitHub from this local machine.
Its local component commands are the evidence reported above.

## Files changed in Phase 2

### Repository and CI

`.github/workflows/ci.yml`, `.gitleaksignore`, `.gitignore`, root `package.json`/lock,
`playwright.config.ts`, `scripts/run-e2e-isolated.ps1`, `e2e/helpers.ts`, and portal ESLint files.

### Backend

`backend/.env.example`, `bootstrap/app.php`, `composer.json`, `composer.lock`, `routes/api.php`,
`config/cors.php`, `config/sanctum.php`, `config/services.php`, `config/security.php`,
`ApiErrorCode.php`, customer/staff middleware, three broadcasting channel authorizers,
customer/staff document and report controllers, `AuditLog.php`, `ReportMessage.php`,
`AuditLogService.php`, document storage/security/verification services, osquery services and
controllers, OTP/reset jobs and SMS drivers, `E2EOtpDriver.php`, report attachment migration, and
the associated authentication, authorization, audit, document, report, queue and osquery tests.

### Portals

The four `next.config.ts` files; client/staff/admin token helpers; admin staff role typing; Security
Center token/login/sidebar/API typing and token tests.

## Remaining risks and mandatory deployment actions

1. Browser bearer sessions remain readable by same-origin JavaScript until the complete BFF/cookie
   migration is designed and tested.
2. Staff/admin/security MFA and just-in-time privileged elevation are not implemented.
3. Audit tables are application-level tamper-evident but need DB privilege separation and external
   append-only sealing.
4. Real ClamAV/EICAR, TLS, production CORS domains, reverse-proxy HSTS and restore exercises need
   environment evidence.
5. CSP still permits inline script/style for Next.js compatibility; nonce-based CSP requires a
   coordinated rendering change.
6. Host telemetry remains coupled to the API process and collects data requiring a documented
   purpose, access policy and retention period.
7. Existing GitHub actions outside the new SBOM control still use mutable major tags.
8. Full MariaDB suite isolation should move each subprocess-concurrency group to a disposable
   database rather than relying on ordering.

## Required production settings

At minimum: `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY` and dedicated versioned
`AUDIT_HMAC_KEY`, `CORS_ALLOW_LOCAL_DEVELOPMENT=false`, exact HTTPS CORS origins,
`DOCUMENT_MALWARE_SCAN=required`, private `DOCUMENTS_DISK`, `SANCTUM_TOKEN_EXPIRATION=60`, a
non-sync queue, supervised worker/scheduler/Reverb, TLS/HSTS, separate migration/application DB
identities, secret-manager injection and tested encrypted backups.

## Self-review and Phase 3 boundary

The changes are additive or one-time compatible migrations, preserve local optional scanning,
preserve existing API payloads, and do not introduce a partial cookie architecture. Infected files,
suspended identities, cross-principal requests and missing branch scope fail closed. The report
records every observed failure and distinguishes local proof from deployment requirements.

The exact next phase is **Phase 3 — asynchronous work and measured performance**: transactional
outbox/after-commit delivery, idempotent jobs, pagination and query budgets based on measurements.
No Phase 3 feature was implemented in this batch.
