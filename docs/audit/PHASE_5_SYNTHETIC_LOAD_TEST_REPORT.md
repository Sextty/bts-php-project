# BTS Bank — Phase 5 Synthetic Data & Real Load Test Report

Date: 24 August 2026  
Scope: Phase 5 only  
Marker: `SYNTHETIC_LOAD_TEST_ONLY`

## Decision

Phase 5 is complete on the tested Windows/XAMPP workstation. The load tool drives real authenticated Laravel API workflows against a fresh, fingerprinted MariaDB database; it does not manufacture completed workflows with direct inserts. All required local campaigns, correctness checks, regression suites, builds, E2E scenarios, and dependency audits passed.

These measurements characterize this workstation and the local eight-worker PHP proxy. They are not a production SLA or a claim that 500 users, 500K rows, or 1M rows have been capacity-tested.

Phase 6 is **BI / Analytics / Advanced Infrastructure**. It was not started.

## Implementation checklist

- [x] Preserve and validate the existing `bts:generate-data` subsystem.
- [x] Select a free, maintainable HTTP/WebSocket-capable load engine.
- [x] Use separate synthetic identities and real authentication for every virtual user.
- [x] Exercise login, application, staff/admin review, appointment, chat/Reverb, full, and read-heavy workflows.
- [x] Create a dedicated MariaDB database for each campaign and guard cleanup with name plus signed fingerprint checks.
- [x] Add bounded accounts, concurrency, duration, ramp, think-time, seed, profile, branch-mode, scenario, pattern, and threshold controls.
- [x] Provide one CLI engine, a Windows launcher/setup flow, and a Tkinter desktop controller.
- [x] Export JSON, CSV, and Markdown results without credentials, OTPs, bearer tokens, or environment secrets.
- [x] Correlate load with queue, outbox, failed jobs, Reverb, health, PHP memory, and MariaDB counters.
- [x] Verify post-load business integrity and implement safe stop.
- [x] Add a five-user MariaDB/API smoke job to CI.
- [x] Run required generator, load, backend, frontend, E2E, and security validation.

## Engine decision

| Option | Assessment | Decision |
|---|---|---|
| Grafana k6 1.5.0 | Single Windows binary, deterministic JavaScript, mature VU/pattern/threshold metrics, HTTP and WebSocket support, CI-friendly summary export, open-source and no paid service required. | **Chosen** |
| Custom Python async runner | Could avoid a binary, but would require custom connection scheduling, percentiles, thresholds, interruption semantics, reporting, and validation of the harness itself. | Rejected |
| Locust | Mature and open-source with a useful web UI, but introduces a Python dependency stack and duplicates the requested standalone desktop control while offering no advantage for this repository. | Rejected |

k6 owns concurrency and protocol measurements. Laravel owns identity preparation, safety activation, real domain workflows, operational telemetry, and post-run correctness. The Python standard library supplies only the loopback OTP collector, local reverse proxy, and Tkinter GUI.

## Architecture and safety boundary

```text
Tkinter GUI / run.ps1
        |
        +-- creates bts_load_YYYYMMDDHHMMSS_PID
        +-- migrates + optionally runs existing synthetic generator
        +-- writes signed short-lived marker
        +-- creates synthetic identities only
        +-- starts loopback OTP collector, API proxy/workers,
        |   queue worker, scheduler and Reverb
        |
        +--> k6 -> real Laravel API -> isolated MariaDB
        |                       |-> database queue/outbox -> Reverb
        |
        +-- waits for async drain -> verifies invariants -> exports
        +-- removes only the fingerprinted load database by default
```

Activation requires all of the following: `APP_ENV=loadtest`, `BTS_LOAD_TEST_ENABLED=true`, a database matching the strict `bts_load_<14 digits>_<safe suffix>` pattern, and a valid signed marker bound to the campaign and database. The local runner accepts loopback targets only. There is no production override. The special OTP driver accepts only a loopback collector and a 32+ character ephemeral token. Normal development and production bindings remain unchanged.

The runner never deletes pre-existing rows. Default cleanup removes only the database it created after revalidating its exact name and fingerprint. `-KeepDatabase` preserves that isolated database for diagnosis. Stop asks k6 to stop opening workflows, permits in-flight requests to finish, drains asynchronous work, verifies completed records, and then performs guarded cleanup; it never stops MariaDB.

## Existing synthetic generator

The existing factory/seeder/command architecture remains in place. Phase 5 adds no schema migration and does not replace its profiles, deterministic RNG, checkpoints, lock, chunked inserts, state distribution, foreign-key ordering, metadata-only document mode, branch routing, appointment construction, notifications, or audit history.

Measured isolated MariaDB runs:

| Profile | Customers | Applications | Related rows | Time | Peak PHP memory | Measured DB size | Result |
|---|---:|---:|---:|---:|---:|---:|---|
| small | 1,000 | 1,300 | 18,658 | 4 s | 54 MiB | not separately sampled | PASS |
| medium | 50,000 | 65,000 | 931,372 | 353 s | 76 MiB | about 454 MiB | PASS |

Both runs used deterministic synthetic Tunisian-style data and metadata-only documents. No real customer data was used. Reproducibility, restart/checkpoint, uniqueness, relationships, valid status paths, branch routing, appointments, notifications, and audit integrity are covered by the generator test suite.

`large` and `massive` were deliberately not executed on this 16 GB workstation. Linear planning estimates from the medium run are approximately 58.8 minutes / 4.54 GiB for large and 117.7 minutes / 9.08 GiB for massive. These estimates exclude non-linear index, buffer-pool, filesystem, and binlog effects and are not capacity guarantees.

## Scenarios and controls

Implemented scenarios:

- `login`: OTP authentication and authenticated dashboard reads.
- `application`: create, client, credit, project, real synthetic file uploads, validations, and submission.
- `staff_review`: branch-scoped queue and staff decision.
- `admin_review`: staff-approved item and final admin decision.
- `appointment`: proposal followed by customer decision; automatic dates start after today.
- `chat`: authorized report message, database queue/outbox, and Reverb path.
- `full`: complete application-to-appointment path plus chat.
- `read`: dashboards, lists, notifications, audit/queues as authorized.

Every virtual user has a distinct phone/email, OTP exchange, authentication token, and application context. Wrong-branch access is actively attempted and must be denied. The 4xx counts in appointment/chat campaigns are these expected authorization denials, not workflow errors.

Controls include 1–500 accounts, 1–100 concurrent workflows, 1–3 applications/account, seed, branch mode, scenario, burst/ramp/sustained/spike/soak pattern, duration, ramp-up, think time, and configurable error/p95/p99 thresholds. The GUI provides Smoke, 5, 10, 20, 30, 50, 100, 500, and Custom presets and warns before heavy campaigns.

## Measured environment

| Component | Value |
|---|---|
| OS | Microsoft Windows NT 10.0.26200.0 |
| CPU | Intel Core i7-10750H @ 2.60 GHz |
| RAM | 16,989,003,776 bytes (about 15.8 GiB) |
| MariaDB | XAMPP MariaDB 10.4.32, `127.0.0.1:3306` |
| PHP | 8.2.12 |
| Laravel | 12.66.0 |
| Node.js | v24.11.1 |
| k6 | v1.5.0 |
| Source revision | `9d4e9dd3defe053cedec9f544ab68dec4d9357de` plus working Phase 5 changes |
| Local API harness | maximum 8 PHP built-in workers behind the loopback proxy |

CPU and disk time series were not available from a trustworthy source and are intentionally not invented. Reports capture PHP request memory, DB connections, deadlocks and global lock-wait counters; operating-system monitoring is still required for production-like saturation studies.

## Required load campaigns

Latency and RPS below use the dedicated `bts_api_*` metrics, so OTP collector and stop-control polling do not contaminate API baselines.

| Campaign | Pattern | Workflows | API requests | RPS | p50 | p90 | p95 | p99 | Failed | Result folder |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| Application 20 accounts / 20 concurrent | burst | 20 | 240 | 6.56 | 2,652 ms | 4,149 ms | 4,526 ms | 6,452 ms | 0 | `20260824162005-39104` |
| Application 30 accounts / 30 concurrent | burst | 30 | 360 | 6.63 | 4,541 ms | 6,422 ms | 6,797 ms | 8,353 ms | 0 | `20260824162110-9756` |
| Application 100 accounts / 20 concurrent | sustained batches | 100 | 1,200 | 8.24 | 1,966 ms | 4,353 ms | 5,086 ms | 8,025 ms | 0 | `20260824162235-26820` |
| Appointment, one branch, 20 / 20 | burst | 20 | 440 | 10.10 | 1,702 ms | 3,265 ms | 3,529 ms | 3,903 ms | 0 | `20260824162656-47708` |
| Chat/notification/Reverb 10 / 10 | burst | 10 | 240 | 9.67 | 818 ms | 1,571 ms | 1,860 ms | 2,716 ms | 0 | `20260824162853-43144` |

All campaigns had 0% workflow error, zero timeout, zero validation failure, zero authentication failure, zero 5xx, zero failed job, and zero Reverb reachability failure. Maximum safely demonstrated concurrency is **30** on this workstation. A higher configured limit is for controlled use on stronger isolated hosts, not evidence of local capacity.

The 30/30 p95 of 6.8 seconds shows the local Windows PHP harness is saturated before correctness fails. The 100/20 batching result has better throughput and latency than a simultaneous 30-user burst. Production sizing needs a real web server, representative TLS/networking, production-like MariaDB settings, longer sustained/soak runs, and external OS metrics.

## Correctness and concurrency results

The verifier passed every applicable assertion after each complete campaign:

- exact workflow count and no unfinished/partial advanced application;
- customer ownership and valid domain status;
- unique customer email/phone, application number, client number, and project number;
- routed submissions and branch isolation, including denied wrong-branch access;
- valid audited state transitions and complete audit hash chain;
- one appointment attempt, no active slot collision, no scheduling today, and no branch/day over-capacity;
- notification deduplication;
- zero unauthorized acceptance.

The single-branch 20/20 appointment campaign completed without collision or deadlock. Dedicated MariaDB concurrency tests separately passed appointment decision and concurrent audit-write cases. MariaDB reported zero deadlock during all measured campaigns. Its `lock_waits` status is a server-global cumulative counter, not a per-campaign delta, so the report does not mislabel it as campaign contention.

The safe-stop campaign requested stop during a 30-account run at concurrency 5. Ten already-started workflows completed successfully; the other twenty never started. The strict expected-count assertion intentionally failed because this was an interruption test, while every ownership, state, uniqueness, appointment, branch, notification, and audit assertion passed. No partial record remained, asynchronous work drained in 1.072 seconds, and guarded cleanup removed only the isolated test database.

## Queue, outbox and Reverb

Workers, scheduler, health/readiness probes, and Reverb are started by the runner. Operational samples are emitted live to the GUI and exported with the report. After each campaign the runner waits up to 180 seconds for database queue and outbox drain, then fails if ready/reserved/delayed/pending/processing/failed work remains.

- Application campaigns drained in 0.438–1.128 seconds.
- Appointment contention peaked at 87 observed pending items and drained in 54.487 seconds.
- Chat/Reverb peaked at 53 observed pending items and drained in 23.700 seconds.
- Final ready, delayed, reserved, pending, processing, and failed counts were all zero.
- Failed jobs: zero. Reverb reachability failures: zero.

The load runner therefore exposes backlog instead of silently accepting queued work. A worker outage causes drain timeout/readiness failure and a non-zero run result.

## Regression and security validation

| Validation | Result |
|---|---|
| Complete Laravel suite | PASS — 419 passed, 3 skipped (MariaDB-only cases under SQLite), 3,073 assertions, 143.39 s |
| Dedicated MariaDB concurrency tests | PASS — 3 tests, 57 assertions, 13.60 s |
| Load-test safety tests | PASS — 3 tests, included in full suite |
| Fresh MariaDB migration in every load campaign | PASS |
| Synthetic generator small and medium | PASS |
| Client lint / TypeScript / production build | PASS / PASS / PASS |
| Staff lint / TypeScript / production build | PASS / PASS / PASS |
| Admin lint / TypeScript / production build | PASS / PASS / PASS |
| Security Center lint / TypeScript / Vitest / build | PASS / PASS / 8 tests PASS / PASS |
| Isolated Playwright suite | PASS — 9/9 scenarios in 7.8 min |
| Composer strict validation and audit | PASS / no known vulnerabilities |
| npm production audits, four portals | PASS — 0 vulnerabilities |
| Gitleaks tracked Git history | PASS — 15 commits, no leaks |
| Gitleaks tracked working diff | PASS — no leaks |

The first dedicated MariaDB test attempt could not locate `mysql.exe` because the XAMPP binary directory was absent from `PATH`; this was a test-shell setup failure, not an application failure. Re-running with `C:\xampp\mysql\bin` on `PATH` passed. A pathless stdin scan of untracked files flagged two fixed test idempotency/deduplication strings as generic-key false positives; their source paths are explicitly documented in `.gitleaksignore`. Git history and tracked diff scans are clean. A recursive whole-directory scan was not used because dependency/cache trees produce unbounded noise; this limitation is not hidden.

The lightweight CI job uses MariaDB 11.4, ephemeral secrets, a checksum-verified k6 binary, five real API workflows, asynchronous drain, and correctness verification. Its YAML parses locally; the remote GitHub Actions job cannot be claimed as run until CI executes it.

## Genuine load-discovered fixes

Three minimal backend retries prevent transient MariaDB concurrency failures without bypassing constraints or changing the normal workflow:

- OTP issuance locks the exact customer row, inserts the new code, then invalidates older codes, avoiding empty-range gap-lock deadlocks; transaction retries increased to five.
- Application-number allocation transaction retries increased to five.
- Critical credit-application transactions retry up to five times.

The test-only OTP collector socket backlog was raised to 256 and its worker threads made daemonized so harness polling is stable during bursts. These changes do not alter production authentication or runtime bindings.

Final cleanup also removes the exact validated temporary campaign directory after its credential manifest is deleted. A one-user proof campaign confirmed that the isolated database, signed marker, process ports, and temporary directory are all absent after success; only sanitized result exports remain.

## Files changed in Phase 5

### Load runner and desktop UI

- `tools/synthetic-load-tester/k6/bts-load-test.js`
- `tools/synthetic-load-tester/k6/fixtures/synthetic-proof.txt`
- `tools/synthetic-load-tester/run.ps1`, `run.bat`
- `tools/synthetic-load-tester/setup.ps1`, `setup.bat`
- `tools/synthetic-load-tester/gui.py`, `gui.bat`
- `tools/synthetic-load-tester/otp_collector.py`
- `tools/synthetic-load-tester/reverse_proxy.py`
- `tools/synthetic-load-tester/README.md`

### Laravel integration and tests

- `backend/config/load_testing.php`
- `backend/app/Services/LoadTesting/LoadTestSafetyGate.php`
- `backend/app/Services/Sms/LoadTestOtpDriver.php`
- `backend/app/Http/Controllers/LoadTestStatusController.php`
- `backend/app/Console/Commands/PrepareSyntheticLoadTest.php`
- `backend/app/Console/Commands/VerifySyntheticLoadTest.php`
- `backend/tests/Feature/LoadTesting/SyntheticLoadTestSafetyTest.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/routes/api.php`
- `backend/config/filesystems.php`
- `backend/app/Services/SyntheticData/SyntheticDataSafetyGate.php`
- `backend/app/Services/OtpService.php`
- `backend/app/Services/ApplicationNumberService.php`
- `backend/app/Services/CreditApplicationService.php`
- `backend/.env.example`

### CI and documentation

- `.github/workflows/ci.yml`
- `.gitignore`
- `docs/SYNTHETIC_BIG_DATA.md`
- `docs/PRODUCTION_OPERATIONS.md`
- `docs/audit/BTS_UPGRADE_ROADMAP.md`
- `docs/audit/PHASE_5_SYNTHETIC_LOAD_TEST_REPORT.md`

## Remaining bottlenecks and limits

1. The local PHP built-in server/proxy, not a production web tier, dominates latency at burst concurrency. Validate Caddy/Nginx/Apache/PHP-FPM separately before production sizing.
2. The 50 and 500 presets are implemented but were not run locally. Start with monitored 50-user tests on an isolated stronger host.
3. `large` and `massive` generator profiles need measured isolated-host runs with disk, buffer-pool, index, and binlog monitoring.
4. Reverb is validated through real queued broadcast dispatch and reachability; a browser-equivalent Echo subscription soak remains a future specialized test.
5. CPU and disk time-series collection requires an external OS monitor. No Phase 6 observability infrastructure was introduced.
6. Thresholds are configurable and correctness thresholds are strict, but banking SLAs must be agreed from a production-like baseline rather than hardcoded from this workstation.

## Final self-review

Safety gates are fail-closed, synthetic identities use reserved namespaces, each campaign is isolated, authentication remains real, and cleanup is exact-target only. The five mandatory campaigns produced no workflow or business-integrity failure. Regression coverage across Laravel, MariaDB concurrency, all four portals, Playwright, dependencies, and tracked secret scans is green. Known limits are explicit and do not invalidate the Phase 5 objective.

**SAFE TO CONTINUE TO PHASE 6**
