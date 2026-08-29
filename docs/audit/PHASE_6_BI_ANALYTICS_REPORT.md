# BTS Bank — Phase 6 BI / Analytics / Advanced Infrastructure Report

Date: 24 August 2026  
Scope: Phase 6 only  
Repository state used: validated Phase 5 plus the existing Phase 0–5 working tree

## Executive decision

```text
METABASE: DEFER
SUPERSET: SKIP
EXTERNAL SEARCH: DEFER
OBJECT STORAGE MIGRATION: DEFER
DATA WAREHOUSE / ETL / READ REPLICA: DEFER
```

Phase 6 implements the smallest architecture that satisfies the demonstrated need: permissioned,
bounded aggregate Laravel APIs over measured MariaDB queries, plus Admin and branch-restricted Staff
dashboards. It adds no distributed service and no new source of truth.

The result is **PROJECT / DEMO READY** and has passed the repository's final local validation. It is
**not REAL BANK PRODUCTION READY**, certified, regulated, or approved for real funds/customer data.

## Implementation checklist

| Requirement | Result | Evidence |
|---|---|---|
| Inspect actual architecture/schema/dashboard growth | PASS | Existing audits, migrations, models, services, Phase 5 datasets reviewed |
| Decide BI platform before installing | PASS | Neither installed; one preferred future candidate only |
| Secure bounded analytics API | PASS | Permission middleware, 730-day maximum, aggregate-only payloads |
| Admin analytics UI | PASS | `/analytics` using current Admin design system |
| Branch-scoped Staff analytics | PASS | Forced authenticated branch; tampering returns 403 |
| Keep Security Center role-specific | PASS | No financial analytics permission or screen added |
| Credit/workflow/appointment/branch analytics | PASS | Deterministic feature tests and E2E |
| Safe CSV/JSON exports | PASS | Aggregate-only, 2,500-row cap, audit event, rate limit |
| Data-quality validation | PASS | Ten read-only checks; small/medium synthetic data 10/10 |
| Measure before indexing | PASS | Medium baseline, EXPLAIN plans, targeted reversible migration, remeasure |
| Small and medium benchmarks | PASS | 1,000 and 50,000 synthetic customers; no massive generation |
| Preserve Phase 5 | PASS | 20/20 workflow burst, zero failures, all correctness checks |
| Full regression/security validation | PASS with disclosed style debt | Functional/security gates green; historical global Pint debt remains |

## Architecture implemented

```text
Admin portal                    Staff portal
global/selected branch          authenticated branch only
       \                         /
        permission + request validation
                    |
          aggregate Analytics API
                    |
     indexed/bounded MariaDB read queries
                    |
            existing OLTP schema
```

The analytics service is a module inside Laravel. It reads existing entities and audit events. It
does not mutate the state machine, schedule appointments, repair records, materialize a second truth,
or expose BI credentials to Next.js. Responses contain aggregates, labels, dates, counts, durations,
and amounts—not customer identity rows, documents, passwords, tokens, OTPs, or secrets.

MariaDB remains appropriate at current measured scale. A read replica/reporting database becomes
reasonable only after concurrent analytics demonstrably harms transactional latency or recurring BI
users require independent availability/retention.

## BI platform comparison

| Criterion | Metabase | Apache Superset | BTS conclusion |
|---|---|---|---|
| Setup | Single Java application/JAR or container; still needs production app DB and operations | Python platform with multiple dependencies, metadata DB, cache/worker deployment | Metabase lower operational burden |
| Windows/local | Runnable locally through Java/Docker | Windows is not officially supported | Metabase materially better for current Windows/XAMPP development |
| MariaDB | Listed as supported | Supported through MySQL-compatible driver | Both viable |
| Dashboards/filtering | Strong self-service questions, dashboards, filters | Strong SQL/visualization/exploration | Both exceed current fixed portal need |
| Permissions | Collections/data permissions; row/column controls depend on plan/design | Dataset/dashboard roles and row-level security | Either still requires BTS-approved reporting views/account |
| Embedding | Public/static options; full-app embedding is commercial | Embedded SDK/guest tokens with additional security design | Native portal pages avoid licensing/auth integration now |
| Performance | Depends on source queries/cache | Depends on source queries/cache/worker stack | Neither removes OLTP query cost by itself |
| Maintenance | Medium | High | Current team gains no measured return from either now |
| Security | Must use read-only least-privilege DB user and restricted content | Same, plus broader platform configuration | Direct unrestricted primary access rejected |
| License | Open-source edition AGPL; advanced embedding/enterprise features have commercial terms | Apache License 2.0 | License alone does not justify deployment |
| Production | Supported deployment patterns exist | Production deployment is substantial; Docker Compose docs warn against production use | Both require real operations, TLS, backup, patching, monitoring |
| BTS suitability | Best future candidate for recurring self-service BI | Too much platform/Windows complexity for measured requirements | `METABASE: DEFER`, `SUPERSET: SKIP` |

Primary references: [Metabase supported databases](https://www.metabase.com/docs/latest/databases/connecting),
[Metabase read-only account guidance](https://www.metabase.com/docs/latest/databases/users-roles-privileges),
[Metabase licensing](https://www.metabase.com/license/),
[Metabase full-app embedding](https://www.metabase.com/docs/latest/embedding/full-app-embedding),
[Superset MariaDB/MySQL support](https://superset.apache.org/docs/databases/supported/mysql/),
[Superset Docker/Windows guidance](https://superset.apache.org/admin-docs/installation/docker-compose/), and
[Superset embedding](https://superset.apache.org/user-docs/using-superset/embedding/).

No BI product was installed. If the trigger is reached, the approved future flow is:

```text
Metabase -> dedicated read-only account -> approved minimized views/read model -> replica/reporting DB
```

## Analytics delivered

### Application and decision analytics

- totals and creation timeline;
- current status distribution using only real status constants;
- approval, rejection, cancellation, pending counts/rates;
- actual decision-event timeline from audit timestamps;
- average submit-to-decision time;
- branch workload and processing comparisons;
- project geography, category, and activity distributions.

Decision queries combine legacy explicit actions with `status.changed` audit events and deduplicate by
application/day/event. This preserves compatibility across existing audit history.

### Credit analytics

- total/average requested amount;
- requested amount by existing currency;
- existing request type distribution;
- EQP/FDR/AMG/CHP requested components;
- EPR presence based on the existing EPR document type.

No “approved amount” is reported because no authoritative approved-amount field exists. This omission
is intentional and prevents fabricated financial semantics.

### Workflow analytics

- entries/completions per actual state-machine stage;
- stage durations where timestamps/audit events make them measurable;
- bottleneck comparison;
- cancellation points.

The `CreditApplicationStateMachine` and every scheduling rule remain unchanged.

### Appointment analytics

- appointments by day/branch/status;
- occupied versus configured capacity and utilization;
- cancellation and rescheduling/attempt counts;
- future scheduling load.

All queries are read-only. The rule that automatic appointments begin on the next valid working day
is also included in data-quality validation.

### Interfaces

- Admin `/analytics`: global and selected-branch dashboard plus data quality.
- Staff `/analytics`: own-branch operational dashboard.
- CSV/JSON aggregate export for timeline, statuses, branches, appointments, workflow, credit, and
  geography.

No redesign was performed. Existing Tailwind components, navigation, tokens, accessibility patterns,
loading/error states, and responsive layouts were reused.

## Security and privacy model

| Control | Implementation |
|---|---|
| Authentication | Existing staff bearer authentication and suspension checks |
| Authorization | `analytics.view` / `analytics.export` permissions |
| Branch isolation | Branch staff scope is server-forced; different `branch_id` is forbidden |
| Global access | Existing admin/super-admin global semantics preserved |
| Security Center | Financial analytics denied |
| Customer | Staff route middleware denies access |
| Input bounds | Valid dates/status/branch and maximum 730 days |
| Export bounds | Aggregate datasets only, max 2,500 rows, rate limit 10 |
| Audit | Every export appends `analytics.aggregate_exported` to the integrity chain |
| Caching | Private/no-store response policy; CSV adds `nosniff` |
| Sensitive data | No identities, documents, credentials, OTPs, tokens, or secrets in analytics payloads |

The portal never receives database credentials. A future external BI deployment must use a dedicated
read-only user and approved PII-minimized views/read model, never application/migration credentials.

## Data-quality controls

`php artisan analytics:verify-data-quality --json` performs ten non-mutating checks:

1. valid application statuses;
2. submission not before creation;
3. submitted applications have branches;
4. appointments reference applications;
5. appointment/application branches match;
6. auto-future appointments are after their creation day;
7. unique request numbers;
8. unique client numbers;
9. unique project numbers;
10. financing components equal the requested total.

Violations are reported, never silently corrected. Both isolated benchmark datasets passed 10/10.

## Query optimization and database change

### Baseline evidence

The first recorded 50,000-customer snapshot took 33,075.150 ms (29 queries). The largest proven
bottlenecks were project dimensions (~4.5 seconds each), request type (~7.4 seconds), and repeated JSON
audit-state scans (~1.3–1.7 seconds). A cold observation reached ~66.7 seconds but was not used as the
formal comparable baseline.

`EXPLAIN`/MariaDB JSON analysis showed table scans/temp grouping and inefficient join order. A measured
covering-index/straight-join geography plan reduced that representative query from ~10.5 seconds to
~0.58 seconds before the complete snapshot was remeasured.

### Migration

`2026_08_24_180000_add_measured_analytics_indexes.php` adds:

- `audit_logs.analytics_status`, generated from `new_state.status`;
- `(action, analytics_status, credit_application_id, created_at)`;
- project covering indexes for city, delegation, type, and activity;
- a credit-request covering index for request type.

The generated column avoids repeated JSON extraction. Indexed `UNION ALL` branches separate direct
actions from transition statuses. Dimension queries use their demonstrated covering indexes. Raw
timeline audit events are grouped in SQL rather than retained in PHP.

The change is additive and reversible. No rows are rewritten by application logic. Existing medium
upgrade verification preserved all 65,000 applications. Operational risk remains index-build time and
metadata locks; the populated local migration took about 54 seconds, so production needs an approved
maintenance/online-DDL plan and backup/rollback rehearsal.

### Results

Environment: local XAMPP MariaDB 10.4.32, port 3306, isolated synthetic databases.

| Dataset | Customers | Applications | Appointments | Audit rows | Snapshot | DB queries/time | Memory delta | Quality |
|---|---:|---:|---:|---:|---:|---:|---:|---|
| Small optimized | 1,000 | 1,300 | 566 | 9,504 | 82.847 ms | 30 / 51.06 ms | 2 MiB | 10/10 |
| Medium baseline | 50,000 | 65,000 | 27,763 | 477,977 | 33,075.150 ms | 29 / 33,026.41 ms | 4 MiB | 10/10 |
| Medium optimized | 50,000 | 65,000 | 27,763 | 477,977 | 3,506.761 ms | 30 / 3,439.58 ms | 2 MiB | 10/10 |
| Medium branch 8 | 50,000 | 65,000 | 27,763 | 477,977 | 2,660.925 ms | 30 / 2,624.97 ms | 2 MiB | 10/10 |

Recorded global improvement: approximately **89.4%**. Peak PHP memory was 30 MiB; response size was
about 79.7 KiB global and 50.4 KiB branch-scoped. Artifacts include the filters, query plans, slowest
queries, row counts, memory, data-quality result, and synthetic marker:

- `phase6-analytics-small.json`
- `phase6-analytics-medium-baseline.json`
- `phase6-analytics-medium.json`
- `phase6-analytics-medium-branch.json`

These numbers prove local improvement, not production scalability or an SLA.

## Advanced infrastructure decision matrix

| Technology | Decision | Evidence | Benefit | Complexity | Trigger |
|---|---|---|---|---|---|
| Metabase | DEFER | Native dashboards satisfy current known use; no recurring analyst workload measured | Self-service BI, scheduled dashboards | MEDIUM | Approved recurring BI need plus reporting read model/account |
| Superset | SKIP | Greater deployment/Windows burden; no capability gap requiring it | Rich SQL exploration | HIGH | Re-evaluate only if requirements decisively exceed preferred candidate |
| MariaDB indexed search | USE | Existing exact/business-ID and metadata lookups fit relational indexes | One secured source, low operations | LOW | Continue measuring real lookup p95 and plans |
| Meilisearch | DEFER | No measured full-text failure | Fast typo-tolerant search | MEDIUM | MariaDB indexed search misses an approved, benchmarked requirement |
| Typesense | SKIP | Same absent need; do not maintain two external-search candidates | Fast search | MEDIUM | Only reconsider if chosen instead of Meilisearch after a formal benchmark |
| Local private document disk | USE | Existing private storage, policy checks, backup tooling; no scale/RPO failure measured | Simple, compatible local operation | LOW | Continue monitoring volume/restore |
| S3-compatible/object storage | DEFER | Driver exists; migration not justified by current volume | HA/durability/lifecycle potential | MEDIUM | Local storage fails approved capacity, HA, durability, or RPO/RTO target |
| Reporting database | DEFER | Optimized bounded reads currently acceptable | Isolate reporting workload | MEDIUM | Analytics concurrency harms OLTP or retention diverges |
| Read replica | DEFER | No production read saturation evidence | Read isolation/failover options | MEDIUM/HIGH | Sustained measured OLTP contention from reporting |
| Separate warehouse | SKIP | No dimensional-history/cross-source requirement or scale evidence | Historical multi-source analytics | HIGH | Approved cross-source history and governance use case |
| ETL pipeline | SKIP | No target warehouse/read model needing it | Controlled transformation | HIGH | Reporting model/warehouse is first approved with ownership and reconciliation |

MariaDB full-text remains an available native capability if actual metadata full-text requirements
appear: [MariaDB FULLTEXT overview](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview).
External engines also create synchronization, rebuild, PII, backup, and availability obligations;
their installation docs make them additional services, not transparent indexes:
[Meilisearch self-hosting](https://www.meilisearch.com/docs/resources/self_hosting/getting_started/install_locally),
[Meilisearch security](https://www.meilisearch.com/docs/learn/security/master_api_keys), and
[Typesense installation](https://typesense.org/docs/guide/install-typesense.html).

## Validation record

### Backend and database

| Gate | Result |
|---|---|
| Full Laravel suite | PASS — 427 passed, 3 skipped Maria-only tests, 3,126 assertions, 52.95 s |
| Analytics correctness | PASS — 8 tests, 53 assertions |
| Deterministic math | PASS — 100 total, 60 approved, 25 rejected, 15 pending; rates/amounts/events verified |
| Fresh MariaDB migration | PASS — isolated DB, all migrations, 31 tables, analytics generated column present |
| Populated MariaDB upgrade | PASS — 65,000 applications before/after; no data loss |
| MariaDB concurrency | PASS — 3 tests, 57 assertions: appointment race/capacity and concurrent audit chain |
| Small/medium quality | PASS — 10/10 checks on both |
| Migration rollback structure | PASS — indexes removed before generated column |

The three skipped tests in the full default SQLite suite explicitly require a dedicated MariaDB
database. They were run separately against an isolated MariaDB database and passed.

### Frontends and E2E

| Gate | Result |
|---|---|
| Client lint / TypeScript / production build | PASS / PASS / PASS |
| Staff lint / TypeScript / production build | PASS / PASS / PASS |
| Admin lint / TypeScript / production build | PASS / PASS / PASS |
| Security Center lint / TypeScript / Vitest / build | PASS / PASS / 8 passed / PASS |
| Isolated Playwright | PASS — 10/10 scenarios in 4.9 minutes |
| Analytics E2E | PASS — Admin, Staff, cross-branch parameter tampering |

Playwright used an isolated fresh MariaDB plus queue worker, scheduler-related services, Reverb, and
all four Next.js portals. Its database was automatically removed.

### Phase 5 regression

Burst application campaign: 20/20 workflows, 0 failures, 240 requests, 15.13 RPS, p50 1,080.3 ms,
p95 2,179.9 ms, p99 2,910.2 ms. Status, ownership, unique IDs, branch assignment, appointments,
notifications, audit, queue drain, and Reverb assertions all passed. The database was isolated and
removed. This remains local development evidence.

### Security and hygiene

| Gate | Result |
|---|---|
| Composer audit | PASS — 0 advisories |
| Root/client/staff/admin/sc production npm audits | PASS — 0 vulnerabilities |
| Gitleaks 8.30.1 | PASS — official checksum verified; 15 commits/~4.57 MiB, no leaks |
| Secret/environment path review | PASS for Phase 6 changes |
| `git diff --check` | PASS; line-ending notices only |

The repository-wide `pint --test` is not green: it identifies historical formatting debt across 47
files from earlier work, mostly unrelated to Phase 6. The sole touched route import-order finding was
corrected; Phase 6 PHP files pass targeted Pint. This non-functional debt is disclosed and was not
mass-rewritten because Phase 6 forbids unrelated changes.

## Files added/changed by Phase 6

### Backend

- analytics configuration, request/filter, aggregation/data-quality services, controller;
- analytics permissions and export error code;
- benchmark and data-quality Artisan commands;
- measured analytics index migration and environment examples;
- staff analytics routes;
- deterministic analytics feature tests.

### Portals and E2E

- Admin/Staff analytics API clients and pages;
- Admin sidebar and Staff header links;
- Staff authenticated download helper;
- analytics Playwright scenario.

### Documentation/evidence

- `docs/ANALYTICS.md`;
- this report;
- four JSON benchmark artifacts;
- roadmap, target architecture, and production-operations updates.

## Complete project review after Phases 0–6

### Architecture achieved

- Laravel modular monolith and MariaDB remain authoritative;
- explicit credit state machine and branch isolation;
- private document storage, malware policy, structured AI verification, and guarded download;
- durable notifications/broadcast outbox with queue observability;
- audit integrity chain and concurrent-write verification;
- four role-specific Next.js portals with isolated authorization;
- health/readiness, structured logs, backup/restore scripts, runbooks;
- safe synthetic generator and isolated load harness;
- secure bounded credit/branch/workflow/appointment analytics.

### Security and operational posture

The project now has strong demonstrable application-level controls and automated regression coverage.
It also avoids unproven microservices, Kubernetes, Kafka, warehouse, BI server, external search, and
distributed object storage. This reduces attack surface and operator burden.

### Remaining technical debt

- repository-wide PHP formatting debt;
- analytics timeline depends on audit-history completeness for older rows;
- no approved-amount field or full historical slowly-changing dimension model;
- 3.5-second local medium global snapshot needs concurrent production-like measurement before an SLA;
- primary-database analytics could contend at materially higher concurrency/retention;
- local XAMPP MariaDB 10.4.32 is development-only and must not define the production platform.

### Real-bank production blockers

- no Tunisian banking/regulatory, legal, privacy, AML/KYC, accounting, or independent security
  certification;
- experimental ledger is not a certified core-banking ledger/payment rail and must not move real funds;
- no proven production HA topology, supported patched database/runtime policy, TLS edge/WAF, capacity
  model, staging soak, or production SLO/SLA;
- no independently approved MFA/JIT privileged-access and final browser-session/BFF design;
- no production secrets manager/HSM/KMS, off-band immutable audit archive, or completed key ceremony;
- ClamAV/EICAR and document controls require real production deployment/monitoring evidence;
- encrypted off-site backups, document restores, disaster-recovery drills, and approved RPO/RTO remain
  operational obligations;
- worker/scheduler/Reverb supervision, alert routing/on-call, penetration testing, threat model, vendor
  assurance, retention/deletion policies, and incident exercises require production governance.

## Final recommendation

All Phase 6 functional, database, performance, portal, E2E, Phase 5 regression, dependency, and secret
gates passed. The disclosed repository-wide formatting debt is pre-existing, non-functional, and was
kept out of scope to preserve minimality.

**PHASE 6 COMPLETE — FINAL PROJECT VALIDATION PASSED**
