# BTS Bank — Phase 3 asynchronous work and measured performance report

Date: 23 August 2026  
Scope: Phase 3 only  
Result: Phase 3 acceptance criteria passed

## 1. Executive result

Phase 3 removed network delivery and broad staff-notification fan-out from critical request
transactions without weakening the status machine, appointment locks, branch isolation, document
security, or audit integrity. A transactional outbox now makes committed notification, provider,
and Reverb work durable, idempotent, retryable, and visible. The database queue remains the
selected backend because measured local throughput does not justify Valkey or Horizon yet.

The phase added only three query indexes supported by MariaDB plans, bounded previously unbounded
application and report-message APIs, and retained the existing bounded export implementation. No
Phase 4 observability stack, Phase 5 load platform, or Phase 6 data platform was started.

## 2. Environment and method

Measurements were made on Windows, an Intel Core i7-10750H at 2.60 GHz, 15.8 GiB RAM, PHP 8.2.12,
Node 24.11.1, and XAMPP MariaDB 10.4.32 on port 3306. They are local engineering measurements,
not a production capacity promise.

All volume and concurrency runs used disposable databases:

- `bts_phase3_baseline_20260823` for small/medium data and read/queue probes;
- `bts_phase3_fresh_20260823` for the fresh migration proof;
- `bts_p0p1_concurrency_20260823` for appointment and audit concurrency;
- an automatically named `bts_e2e_<pid>` database for Playwright.

The normal development database was not reset or loaded. Only synthetic factories and the existing
synthetic generator were used.

## 3. Baseline measurements and bottlenecks

### Synthetic datasets

| Run | Added customers | Added applications | Added related rows | Elapsed | Peak reported memory | Rate |
|---|---:|---:|---:|---:|---:|---:|
| small | 1,000 | 1,300 | 18,714 | 4 s | about 70 MiB | 290 customers/s |
| medium | 50,000 | 65,000 | 932,794 | 232 s | 124 MiB | 216 customers/s |

The combined medium database contained 51,000 users, 66,300 applications, 28,515 appointments,
17,499 report messages, 58,280 app notifications, and 487,987 audit rows before the notification
burst probe.

### Query plans before changes

| Query | Baseline plan observation |
|---|---|
| customer applications by user, newest first | existing `(user_id,status)` key; filesort |
| staff branch/status queue | branch foreign-key index; about 701 examined rows; filesort |
| appointments by branch/date/status/time | branch/date range; about 421 rows; filesort |
| report messages by application | existing application/created-at index; at most 4 rows per application in the medium set |
| notification inbox | existing morph index; about 1 row per tested recipient |
| audit action/date feed | created-at range; about 241,665 candidate rows |

The material bottlenecks were unbounded customer application and chat responses, synchronous staff
fan-out, network broadcast in request paths, and missing durable visibility for committed broadcast
intent. Report-message and notification-inbox indexes proposed during analysis were deliberately
rejected because the measured cardinality and plans did not justify their write/storage cost.

## 4. Architecture implemented

### Transactional outbox

`async_outbox_events` stores the event type, aggregate identity, JSON payload, unique deduplication
key, lifecycle state, attempts, availability, timestamps, queue delay, runtime, and a sanitized
failure class. Its states are `pending`, `processing`, `retrying`, `processed`, and `failed`.

Business code writes outbox intent inside the same MariaDB transaction as the domain change.
`ProcessAsyncOutboxEventJob` is dispatched after commit and is only an acceleration path: the
scheduled `outbox:dispatch` command recovers any committed intent missed between commit and queue
dispatch. A stale processing lease is returned to `retrying`. Terminal failures are not replayed
automatically; an operator must use `outbox:dispatch --include-failed` after correcting the cause.

The job has five attempts, a 30-second timeout, bounded backoff of 2, 10, 30, 120, and 300 seconds,
and an idempotent unique job key. A missing Reverb transport leaves the row in `retrying`; exhausted
work is visible as `failed` and in Laravel failed jobs.

### Side-effect boundaries

- application-submit staff notifications are one outbox audience intent in the request;
- customer report messages persist message, broadcast intent, and staff-audience intent atomically;
- staff report messages, close/reopen notices, manual appointments, account ban/unban notices,
  review decisions, and appointment proposal/accept/reject audit work now share explicit
  transactions where required;
- branch-targeted staff fan-out executes in a worker and selects only active matching-branch staff
  plus global admin/security roles, in chunks of 200;
- per-recipient notifications, external email/SMS deliveries, and Reverb broadcasts remain
  deduplicated and reconcileable;
- Reverb itself was preserved and is contacted only by the outbox worker;
- the synchronous queue driver remains functional for local development.

Audit writes remain synchronous because an auditable banking state change must not commit without
its integrity-chain row. Malware scanning remains synchronous and fail-closed when required,
because accepting an unscanned upload and scanning later would change the Phase 2 security
contract. AI document verification remains tied to the validation workflow; moving it was not
justified without redesigning the domain state. OTP/password-reset delivery behavior was preserved
because it is authentication-critical and already has an explicit queue policy.

## 5. Notification and queue measurements

On a synthetic branch audience of 106 recipients:

| Operation | Measured time/result |
|---|---:|
| direct in-request recipient fan-out | 911.29 ms |
| new durable audience-intent write | 5.47 ms |
| worker completion of parent and child outbox events | 6.699 s |
| resulting asynchronous notifications | 106 |
| resulting processed outbox events | 213 |
| remaining jobs | 0 |

This moves approximately 906 ms of measured recipient creation out of the critical request in this
probe. The value is reported as an absolute local measurement, not a generalized percentage.

The database queue probe used 1,000 synthetic no-op jobs:

| Metric | Result |
|---|---:|
| enqueue time | 1.473 s |
| enqueue throughput | 679 jobs/s |
| one-worker drain time | 9.107 s |
| one-worker drain throughput | 109.8 jobs/s |
| remaining jobs | 0 |
| worker exit | success |

An earlier run with verbose worker console rendering measured 290.8 enqueues/s and 80.1 drains/s;
console output was identified as probe overhead and suppressed. The measured quiet run is the
decision input.

### Queue backend decision

**Keep Laravel's database queue. Do not add Valkey or Horizon in Phase 3.** The measured queue
drained completely and showed no evidence that the backing store is the current bottleneck.
Introducing another stateful service would add operational risk without demonstrated benefit.

Reconsider Valkey/Horizon when one or more of these production signals persist after worker tuning:

- ready backlog above 10,000 or oldest ready job above 300 seconds;
- sustained required throughput beyond measured database-worker capacity;
- measurable MariaDB lock/IO contention attributable to `jobs`;
- priority queues, rate balancing, or Horizon supervision become an operational requirement.

## 6. Query changes and evidence

Three indexes were added:

| Index | Query helped | Before | After | Cost |
|---|---|---|---|---|
| `credit_apps_user_id_desc_index (user_id,id)` | customer applications newest first | filesort using `(user_id,status)` | selected new key; no filesort | two bigint entries per application and insert/update maintenance |
| `credit_apps_branch_status_created_index (branch_id,status,created_at,id)` | staff branch/status queue | branch key, about 701 candidates | covering key, about 339 candidates; filesort remains for multi-status ordering | larger composite application index and status/date write maintenance |
| `audit_logs_action_created_index (action,created_at,id)` | action/date audit feed | created-at key, about 241,665 candidates | covering key, about 107,612 candidates | large append-only audit index and extra insert IO |

The representative post-change 50-iteration read probe produced:

| Read probe | Average | p95 | Maximum |
|---|---:|---:|---:|
| customer applications | 0.664 ms | 0.821 ms | 1.707 ms |
| staff application queue | 1.699 ms | 1.947 ms | 9.320 ms |
| audit activity | 1.245 ms | 1.589 ms | 6.701 ms |

The appointment composite index `(branch_id,scheduled_date,status,scheduled_time)` was retained as
a covering filter for the real capacity query. The date range still requires a filesort for time,
so the report does not claim that sort was eliminated. It adds four-column write/storage overhead.

No new message or notification index was retained: the medium data showed a maximum of four
messages per application and two notifications per recipient, and the existing indexes already
bounded those lookups. This is intentional evidence-based restraint.

## 7. Pagination and frontend compatibility

- customer applications now use page pagination, default 25 and maximum 100, while retaining the
  existing `applications` response field and adding metadata;
- customer and staff report messages use `id` cursor pagination, default 100 and maximum 200,
  with `has_more` and `next_before_id` metadata;
- customer/staff notification limits are clamped to 1–100 and mark-all-read is one SQL update;
- existing staff/admin queues, audit/security feeds, appointments, and exports were already
  bounded and remained so;
- portal API types accept the new metadata and optional `before_id` cursor.

The existing security export is capped at 1,000 rows and does not build an unbounded file in API
memory. Its measured/declared bound did not justify a background export subsystem in Phase 3.

## 8. Appointment concurrency

The dedicated MariaDB test executed simultaneous same-slot, same-application, different-time, and
daily-capacity races. It passed 27 assertions in 8.17 seconds. Exactly one competing slot/capacity
request succeeded, duplicate same-application requests were idempotent, and attempts remained
monotonic.

MariaDB global lock counters changed from 52 to 56 waits and from 1,513 to 1,601 ms cumulative
wait time during the measured run: four waits totaling 88 ms. Current waits returned to zero and
the global maximum stayed at 237 ms. No deadlock or double booking was observed. Lock order and
`lockForUpdate` behavior were not weakened.

The separate audit-chain concurrency test passed eight simultaneous inserts, preserved one valid
chain, and passed 20 assertions. Together the MariaDB concurrency tests passed 47 assertions in
their main validation run.

## 9. Operational requirements

Production requires both a continuously supervised queue worker and Laravel scheduler:

```text
php artisan queue:work --sleep=1 --tries=5 --backoff=2 --timeout=60
php artisan schedule:work
```

The scheduler dispatches outbox rows every minute and reconciles unfinished external notification
deliveries every five minutes. Readiness now reports database queue backlog/age/reserved work,
failed jobs, worker/scheduler heartbeat, outbox pending/processing/failed/age, average outbox queue
delay/runtime, and Reverb reachability.

Relevant safe controls are documented in `.env.example`:

- `OUTBOX_HEALTH_MAX_PENDING`;
- `OUTBOX_HEALTH_MAX_AGE_SECONDS`;
- `OUTBOX_HEALTH_MAX_FAILED`;
- `OUTBOX_PROCESSING_TIMEOUT_SECONDS`.

The read-only commands `bts:performance-probe` and `bts:queue-probe` provide repeatable internal
measurements. The queue probe refuses production and refuses database names that do not clearly
identify a test/phase3/benchmark database.

## 10. Validation matrix

| Area | Result |
|---|---|
| Pint on Phase 3 PHP files | PASS |
| full-repository `pint --test` | FAIL — unrelated pre-existing formatting debt remains in 49 legacy/Phase 1–2 files; Phase 3 files are clean |
| full Laravel suite | PASS — 405 tests, 3,016 assertions; 2 Maria-only tests intentionally skipped in SQLite run |
| targeted outbox/audience/Reverb-offline suite after final test additions | PASS — 7 tests, 26 assertions |
| dedicated Maria appointment + audit concurrency | PASS — 2 tests, 47 assertions |
| MariaDB fresh migration | PASS |
| upgrade of populated pre-Phase-3 medium database | PASS; entity counts unchanged, two additive migrations applied |
| client lint / TypeScript / production build | PASS |
| staff lint / TypeScript / production build | PASS |
| admin lint / TypeScript / production build | PASS |
| Security Center lint / TypeScript / Vitest / production build | PASS — 3 files, 8 tests |
| isolated Playwright | PASS — 9/9 in 5.5 minutes, including live Reverb chat |
| Composer validation/audit | PASS — no advisory |
| npm production audits, four portals | PASS — zero reported vulnerabilities |
| `git diff --check` | PASS; only existing Windows line-ending notices |
| local Gitleaks executable | NOT RUN — executable unavailable; repository CI Gitleaks configuration remains present |

The E2E run covered customer registration/OTP, three complete application flows, staff approvals
and rejection, branch isolation, admin final approval, appointment acceptance, live Reverb chat,
Security Center tab-scoped refresh persistence/logout, and suspended security-user denial.

## 11. Files changed in Phase 3

Core additions:

- `backend/database/migrations/2026_08_23_170000_create_async_outbox_events_table.php`
- `backend/database/migrations/2026_08_23_171000_add_phase3_measured_query_indexes.php`
- `backend/app/Models/AsyncOutboxEvent.php`
- `backend/app/Services/AsyncOutboxService.php`
- `backend/app/Jobs/ProcessAsyncOutboxEventJob.php`
- `backend/app/Console/Commands/DispatchAsyncOutbox.php`
- `backend/app/Console/Commands/PerformanceProbe.php`
- `backend/app/Console/Commands/QueuePerformanceProbe.php`
- `backend/app/Jobs/MeasureQueueThroughputJob.php`
- `backend/tests/Feature/Async/TransactionalOutboxTest.php`

Updated backend behavior:

- notification and report broadcast events/services;
- notification, credit application, review, appointment, health services;
- customer/staff report, notification, application, and user-ban controllers;
- scheduler, operations configuration, and `.env.example`;
- notification, health, report-chat, and strict Gemini test fixture coverage.

Updated compatible portal contracts:

- `client/app/applications/page.tsx`
- `client/lib/api/credit-applications.ts`
- `client/lib/api/reports.ts`
- `staff/lib/api/reports.ts`
- `admin/lib/api/reports.ts`

Documentation:

- this report;
- `docs/audit/BTS_UPGRADE_ROADMAP.md`.

## 12. Limitations and deferred work

- Local measurements are single-machine probes, not production SLO evidence. p99 HTTP load,
  request error rates, and multi-host worker behavior require Phase 4 metrics and Phase 5 load work.
- No large/massive generator run was attempted on the laptop; medium already supplied sufficient
  plan and queue evidence. Large/massive and k6 remain Phase 5.
- No Valkey, Horizon, Pulse, Kafka, microservice, search engine, or BI platform was introduced.
- Documents and audit intentionally retain synchronous security/integrity work.
- The frontend exposes cursor-compatible contracts but does not add a UI redesign for loading
  arbitrarily old chat pages.
- A real prolonged Reverb outage/recovery drill under supervision remains an operational Phase 4
  exercise; Phase 3 covers live E2E, worker-offline durability, transport failure retry, delayed
  dispatcher recovery, and terminal failure visibility in automated tests.
- Local Gitleaks could not run because the binary is absent. CI remains the enforcement point.
- A repository-wide Pint check still reports pre-existing style debt outside the Phase 3 change
  set. It was not mass-rewritten because that would make this performance batch non-minimal; CI
  owners should schedule a formatting-only cleanup.

## 13. Final self-review

No valid business transition, authorization rule, branch boundary, document security rule, audit
chain rule, or appointment lock was relaxed. The new migrations are additive and reversible; the
populated medium upgrade preserved all measured entity counts. The database queue is adequate for
the measured workload and its limitations now have explicit health thresholds and migration
triggers.

**SAFE TO CONTINUE TO PHASE 4**
