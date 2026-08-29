# BTS Bank analytics

## Scope and architecture

Phase 6 adds a bounded, read-only analytics layer inside the existing Laravel modular monolith.
The Admin and Staff portals call aggregate API endpoints; they never receive database credentials
or raw customer rows. MariaDB remains the transactional database. No BI server, search engine,
warehouse, ETL pipeline, read replica, or object-storage migration is required at the measured scale.

```text
Admin / branch Staff
        |
        v
permission + validated filters + forced branch scope
        |
        v
Laravel aggregate analytics service
        |
        v
bounded, indexed MariaDB queries (maximum 730 days)
```

Security Center is intentionally excluded from financial analytics. Its existing screens remain
limited to security, access, audit-integrity, and host telemetry.

## Access model

| Principal | Access |
|---|---|
| Branch-restricted staff | Own branch only; changing `branch_id` returns 403 |
| Admin / super admin | Global or selected branch |
| Security role | No credit-financial analytics permission |
| Customer | No staff analytics route access |

The permissions are `analytics.view` and `analytics.export`. Exports are rate-limited, capped,
private/no-store, and written to the audit chain as `analytics.aggregate_exported`.

## HTTP API

All routes use the existing `/api/staff` authentication and permission middleware.

| Method and route | Purpose |
|---|---|
| `GET /api/staff/analytics/overview` | One aggregate snapshot for portal dashboards |
| `GET /api/staff/analytics/export` | Bounded CSV or JSON aggregate export |
| `GET /api/staff/analytics/data-quality` | Admin-only global consistency report |

Supported filters are `from`, `to`, `branch_id`, and an existing application `status`. Export also
accepts `format=csv|json` and `dataset=timeline|statuses|branches|appointments|workflow|credit|geography`.
The default interval is 365 days and the hard maximum is 730 days. Export defaults to 2,500
aggregate rows. There is no unbounded raw-record endpoint.

## Metric definitions

- Applications: totals, current status distribution, creation timeline, approval/rejection/cancellation
  rates, branch volume, and pending workload.
- Decisions: actual audit-event timestamps are used for approval, rejection, cancellation, and
  processing-time calculations. Legacy explicit decision actions and state-transition events are
  combined and deduplicated.
- Credit: requested amount, average requested amount, currency, request type, and EQP/FDR/AMG/CHP
  totals. The schema has no authoritative approved-amount column, so none is invented.
- EPR: measured from the existing EPR document requirement; it is not represented as a fabricated
  monetary field.
- Workflow: stage entries/completions and measurable durations based on current statuses and audit
  events. The state machine itself is unchanged.
- Appointments: daily/branch volume, status, configured slot capacity, utilization, cancellation,
  attempt counts, and future load. Queries are read-only and cannot alter scheduling.
- Geography/activity: existing project governorate/city, delegation, category, and activity fields.

When a current-status filter is supplied, event metrics are computed for applications whose current
status matches that filter. This is a cohort filter, not a historical “status on that date” query.

## Dashboards

- Admin `/analytics`: global KPIs, decision trends, statuses, branch comparison, workflow,
  appointments, credit mix, geography/activity, aggregate exports, and data-quality status.
- Staff `/analytics`: the same operational aggregates restricted to the authenticated staff branch.

Both pages reuse the existing Tailwind design system. Charts consume aggregate series only; no
customer list, identity, document content, token, OTP, or secret is placed in chart payloads.

## Database optimization

Migration `2026_08_24_180000_add_measured_analytics_indexes.php` adds only indexes demonstrated by
the 50,000-customer benchmark:

- generated `audit_logs.analytics_status` derived from `new_state.status`, plus
  `(action, analytics_status, credit_application_id, created_at)`;
- covering project indexes `(ville, credit_application_id)`, `(delegation, credit_application_id)`,
  `(type_projet, credit_application_id)`, and `(activite, credit_application_id)`;
- covering credit-request index `(type_demande, credit_application_id)`.

MariaDB dimension queries use the measured covering index and deterministic join order. SQLite keeps
portable queries for automated tests. Decision analytics use indexed `UNION ALL` branches instead of
repeated JSON extraction. The timeline is aggregated in SQL, so raw audit events are not loaded into
PHP memory.

The generated column is `PERSISTENT` on MariaDB and virtual on SQLite. The migration is reversible;
its `down()` removes the indexes before the generated column. Applying it to a populated database
can hold metadata/index locks, so production deployment still needs a maintenance/change plan.

## Commands

```powershell
cd backend
php artisan analytics:verify-data-quality
php artisan analytics:verify-data-quality --json
php artisan analytics:benchmark --from=2025-08-24 --to=2026-08-23 --json
php artisan analytics:benchmark --branch=8 --json
```

Configuration:

```dotenv
ANALYTICS_DEFAULT_DAYS=365
ANALYTICS_MAX_DAYS=730
ANALYTICS_EXPORT_MAX_ROWS=2500
```

The quality command reports but never repairs or deletes data. Its ten checks cover valid statuses,
workflow dates, branch assignments, appointment relationships/date rules, duplicate business IDs,
and requested-financing totals.

## Measured local baseline

Measured on XAMPP MariaDB 10.4.32/port 3306 with synthetic-only isolated databases. Results are local
development evidence, not a production SLA.

| Dataset | Snapshot | DB time | Queries | PHP memory delta | Result |
|---|---:|---:|---:|---:|---|
| Small: 1,000 customers / 1,300 applications | 82.847 ms | 51.06 ms | 30 | 2 MiB | quality 10/10 |
| Medium baseline: 50,000 / 65,000 | 33,075.150 ms | 33,026.41 ms | 29 | 4 MiB | quality 10/10 |
| Medium optimized: 50,000 / 65,000 | 3,506.761 ms | 3,439.58 ms | 30 | 2 MiB | quality 10/10 |
| Medium branch 8 | 2,660.925 ms | 2,624.97 ms | 30 | 2 MiB | quality 10/10 |

The recorded global improvement is about 89.4%. Raw artifacts and plans are under
`docs/audit/phase6-analytics-*.json`. A production SLA requires representative hardware, concurrency,
cache state, network, retention, and monitoring measurements.

## Future BI/read scaling

`METABASE: DEFER` and `SUPERSET: SKIP`. If recurring analyst-authored dashboards or BI concurrency
materially harms OLTP, first create approved PII-minimized reporting views/read models, a separate
read-only database account, and preferably a replica/reporting database. Then benchmark one Metabase
deployment against defined budgets. Never connect a BI tool to MariaDB with the application or
migration account.

External search is deferred while exact/business-ID and indexed MariaDB search meet the actual
workload. S3-compatible private storage is deferred until measured document volume, HA, durability,
or RPO/RTO cannot be met by the current encrypted private-disk deployment and backup process.
