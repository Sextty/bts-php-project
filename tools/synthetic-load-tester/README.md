# BTS Bank Synthetic Load Tester

Local, reproducible load testing against the real Laravel API. Every campaign is marked `SYNTHETIC LOAD TEST ONLY`, creates a dedicated `bts_load_<timestamp>_<pid>` MariaDB database, and removes only that database after verification. Production and ordinary development databases cannot activate the load-test endpoints or OTP driver.

## Stack and architecture

- k6 1.5.0: virtual users, HTTP workflows, thresholds, metrics, and JSON/CSV/Markdown summaries.
- Python standard library: Tkinter desktop UI, loopback OTP collector, and Windows loopback reverse proxy.
- Laravel: identity preparation, signed environment marker, real authentication/workflows, queue, Reverb, telemetry, and post-run correctness verifier.
- MariaDB: a fresh isolated database for every local run. Foreign keys remain enabled.

The identity preparer creates synthetic accounts and authorized staff only. Application, document, review, appointment, notification, audit, and chat rows are created by the real API during the campaign.

## Install and launch on Windows

Requirements: XAMPP MariaDB on `127.0.0.1:3306`, PHP, Python 3 with Tkinter, and project Composer dependencies.

```bat
tools\synthetic-load-tester\setup.bat
tools\synthetic-load-tester\gui.bat
```

Setup downloads the pinned open-source k6 binary and verifies the official SHA-256 checksum. No paid service is required.

CLI example:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/synthetic-load-tester/run.ps1 `
  -SyntheticProfile none -Scenario application -Pattern burst `
  -Accounts 30 -Concurrency 30 -ApplicationsPerAccount 1 `
  -Duration 10m -Seed demo-30
```

Use `-BranchMode single` for appointment contention in one branch. `-KeepDatabase` preserves only the isolated load database for investigation. The default deletes it after the report is exported. Database credentials are passed through environment variables, never command-line process arguments.

## GUI

### First launch

1. Start XAMPP/MariaDB on `127.0.0.1:3306`.
2. Open the load tester with `gui.bat`.
3. Keep the dedicated backend URL at `http://127.0.0.1:8200`, unless another reviewed loopback port is required.
4. Select the preset/scenario, then click **Préparer l’environnement**.
5. Wait until the environment card shows **READY**, a `bts_load_*` database, active worker/Reverb, and the `SYNTHETIC ONLY` safety marker.
6. Click **Démarrer le test**. Selecting a preset never starts a campaign.
7. Export the sanitized results, then click **Nettoyer**.

The desktop UI configures target, profile, seed, branch mode, scenario, pattern, accounts, concurrency, applications per account, ramp-up, duration, and think time. Presets cover Smoke, 5, 10, 20, 30, 50, 100, 500, and Custom. Heavy profiles require confirmation.

The GUI now has an explicit two-step lifecycle. **Préparer l’environnement** launches the existing runner, creates and migrates a new fingerprinted database, creates synthetic identities, starts the isolated API/proxy, queue worker, scheduler, OTP collector and Reverb, then pauses before k6. **Démarrer le test** is enabled only after Laravel returns the signed marker and every service required by the selected scenario is ready. After a successful campaign the runner remains the owner of the isolated environment until **Nettoyer** is clicked; default cleanup drops only its exact validated database.

**Tester** checks, in order, URL safety, backend liveness, readiness/MariaDB, the protected load-test marker, synthetic database, accounts, worker, and Reverb. A 404 from `/api/load-test/status` while the normal backend is reachable means the safety gate is working and no isolated environment is active; it is no longer displayed as a raw Python exception.

Start, safe Stop, Reset, and Export share the same PowerShell/k6 engine as the CLI. Safe Stop stops starting new workflows; in-flight API transactions are allowed to finish before controlled cleanup. Live logs redact password, OTP, token, authorization, and secret values.

### Common errors

- **Backend inaccessible:** the configured isolated API is not running yet, or its local port is unavailable. Click **Préparer l’environnement**; the runner starts the dedicated API itself.
- **Backend connected, synthetic environment not ready:** a Laravel backend is reachable, but it is the normal development environment or has no valid signed load marker. Do not bypass this check; prepare an isolated environment.
- **MariaDB unavailable:** start MySQL/MariaDB in XAMPP and verify port `3306` and the configured local credentials.
- **k6 unavailable:** run `setup.bat`; it installs the pinned, checksum-verified open-source k6 binary under the tool directory.
- **Reverb unavailable:** verify that port `6201` is free. Chat/full scenarios remain locked until Reverb is active.
- **Port conflict:** the dedicated runner uses the selected backend port, `8299`, `6201`, and a bounded range beginning at `8210`. Close only the process you recognize; the cleanup routine never stops unrelated processes.
- **Environment not prepared:** click **Préparer l’environnement** and wait for READY before starting.

Technical diagnostics stay in the live log. Passwords, OTPs, bearer tokens and secrets are redacted and are never written to result tables.

## Scenarios and patterns

Scenarios: `login`, `application`, `staff_review`, `admin_review`, `appointment`, `chat`, `full`, and `read`.

The write scenarios use a separate customer session per virtual user and real staff/admin sessions per review. `full` covers login through appointment acceptance and chat. The harness also verifies a wrong-branch staff request is denied. `read` supports burst/constant, ramp, spike, sustained, and soak patterns; write scenarios support a deterministic per-account ramp and optional think time.

## Safety boundaries

The target must be loopback for the local runner. Laravel additionally requires all of:

- `APP_ENV=loadtest`;
- `BTS_LOAD_TEST_ENABLED=true`;
- strict database name `bts_load_<14 digits>_<safe suffix>`;
- a signed marker matching the active database and campaign.

There is no production override. The OTP collector and reverse proxy bind only to `127.0.0.1`; the OTP driver refuses non-loopback collectors and requires a strong token. Manifests are temporary and ignored by Git. Synthetic contacts use reserved `.invalid` domains and a dedicated non-customer telephone namespace.

At shutdown the runner removes the credential manifest, signed activation marker, document sandbox, process logs, and exact temporary campaign directory after validating that it is a `bts-load-<timestamp>-<pid>` child of the operating-system temporary directory. Sanitized reports remain under `results/`.

## Metrics, thresholds, and reports

Each result folder contains `report.json`, `report.md`, `summary.json`, `summary.csv`, `workflows.json`, `workflows.csv`, `correctness.json`, `operations.json`, and the sanitized k6 log. Add `-GranularMetrics` only when per-request JSONL is required because it can be large.

Metrics include workflow counts/rate, request rate, average/min/max/p50/p90/p95/p99 HTTP latency, HTTP status buckets, timeouts, validation/authentication failures, queue/failed-job/outbox timing snapshots, Reverb reachability failures, PHP request memory, MariaDB connections, deadlocks, and lock waits. Hardware and exact tool versions are captured with the source revision. CPU and disk utilization are intentionally not invented; use OS monitoring for time-series resource saturation.

Set `-MaxErrorRate`, `-P95Ms`, and `-P99Ms` after measuring a baseline. Correctness thresholds are always strict: zero failed workflow and zero unauthorized acceptance.

Post-run verification checks expected workflow rows, ownership, natural-key uniqueness, complete advanced applications, branch routing/isolation, valid state transitions, appointment attempt/slot/capacity/date rules, notification deduplication, and the complete audit chain. Any k6 or verifier failure makes the command fail while preserving the report.

## CI and limitations

CI runs only a five-user real API application smoke on MariaDB. Large stress campaigns remain manual. The local Windows runner uses at most eight PHP built-in worker processes behind a test-only proxy; its numbers are workstation baselines, not production capacity promises. k6 supports WebSockets, but this phase validates Reverb delivery through real queued broadcasts and operational reachability rather than implementing a browser-equivalent Echo subscription.

The existing `php artisan bts:generate-data` system is separate and preserved. Select `-SyntheticProfile small` or `medium` to prefill the isolated database before a campaign. `large` and `massive` remain deliberate generator-only operations and are never offered as an automatic local load preset.
