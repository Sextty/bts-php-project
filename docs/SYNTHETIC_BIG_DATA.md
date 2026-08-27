# BTS Bank Synthetic Big-Data Generator

## Purpose and safety boundary

This subsystem creates high-volume **synthetic test data** for local development, automated testing, analytics, dashboard validation, performance testing, security monitoring, and database stress testing.

It is not a source of production data. Generated names, identities, contact details, addresses, financial values, files, messages, network addresses, and event histories are artificial. The generator must never receive a production export or use an existing customer row as a template.

The command is additive:

- It never deletes, truncates, or resets existing tables.
- It keeps foreign-key checks enabled.
- It uses the existing database schema, application statuses, document configuration, branches, and permissions.
- It refuses production by default. Production execution requires the independent controls documented below.
- Existing branch rows are reference/master data. The generator reads them for routing and scheduling but does not generate, copy, or replace branches.

For the safest workflow, run the generator against a dedicated disposable test database, never a database shared with real users.

## Command-line interface

Run commands from the Laravel backend directory:

```text
cd backend
php artisan bts:generate-data --profile=small
php artisan bts:generate-data --profile=medium
php artisan bts:generate-data --profile=large
php artisan bts:generate-data --profile=massive
```

Supported options:

| Option | Meaning |
| --- | --- |
| `--profile=small|medium|large|massive` | Selects a supported size profile. |
| `--customers=<count>` | Overrides the profile's customer count. |
| `--applications=<count>` | Overrides the profile's application count. |
| `--seed=<value>` | Selects a reproducible synthetic dataset. Accepted values contain 1–64 letters, numbers, dots, underscores, or hyphens. |
| `--chunk=<count>` | Overrides rows processed per committed batch. |
| `--materialize-documents` | Creates small synthetic files for generated document metadata. |
| `--metadata-only-documents` | Creates document rows without consuming filesystem space for payload files. |
| `--allow-production` | First explicit command-side production acknowledgement. It is insufficient by itself. |
| `--production-confirmation=GENERATE_SYNTHETIC_TEST_DATA` | Exact production confirmation phrase. |

`--customers` and `--applications` are independent. Applications are distributed across the requested customers, so one customer may own zero, one, or several applications.

Distribution is deterministic and balanced. With each built-in 1.3 applications-per-customer profile, 70% of customers receive one application and 30% receive two. Overrides below one application per customer leave a deterministic subset with no application; higher ratios distribute the base count plus one extra across a deterministic permutation.

Configured safety limits are 2,000,000 customers, 3,000,000 applications, at most 3 applications per customer, and a chunk size between 10 and 2,000. The internal insert batch is 500 rows. Invalid or excessive values are rejected before generation.

`--materialize-documents` and `--metadata-only-documents` are mutually exclusive. Without an override, `small` materializes files; `medium`, `large`, and `massive` use metadata-only mode. Metadata-only mode is intended for high-volume runs, where millions of small files would distort a database benchmark. Materialized mode is useful when testing document download, storage, and cleanup behavior.

The command prints the resolved profile, seed, counts, chunk size, document policy, target connection, chunk progress, elapsed time, insertion rate, memory use, and rows inserted by the current invocation before returning.

## Built-in profiles

| Profile | Customers | Credit applications | Date horizon | Default chunk | Documents | Intended use |
| --- | ---: | ---: | ---: | ---: | --- | --- |
| `small` | 1,000 | 1,300 | 2 years | 250 | Materialized | Local development and UI testing |
| `medium` | 50,000 | 65,000 | 3 years | 750 | Metadata only | Analytics and dashboard testing |
| `large` | 500,000 | 650,000 | 5 years | 1,500 | Metadata only | Query, indexing, queue, and API performance testing |
| `massive` | 1,000,000 | 1,300,000 | 7 years | 2,000 | Metadata only | Dedicated database stress testing |

These counts cover the two root business populations. Related row counts depend on application status and controlled distributions:

- Each application has at most one client, credit request, and project row.
- Documents, validation attempts, appointments, messages, notifications, and audit events are one-to-many.
- Applications that have not reached a step do not receive impossible downstream records.
- Advanced applications create more related rows than drafts.

The final command summary reports planned roots and aggregate rows inserted by the current invocation. For an exact per-table total, query the dedicated test database after the run; a resumed or already-complete workload may correctly report few or no new rows.

## Real schema and dependency map

The generator uses the schema declared under `backend/database/migrations` and the models under `backend/app/Models`. It does not create generator-only business tables.

```text
branches
  staff_users
    users.banned_by_staff_id
    credit_applications decision and report-closure actors
    report_messages.staff_user_id
    audit_logs.staff_user_id

users
  otp_codes
  credit_applications
    clients
    credit_requests
    projects
    documents
    validation_steps
    appointments
    report_messages
    audit_logs
  report_messages.user_id
  audit_logs.user_id

branches
  credit_applications.branch_id
  appointments.branch_id

User or StaffUser
  app_notifications through notifiable_type and notifiable_id
```

Insert order follows those dependencies:

1. Load existing branches and create synthetic staff.
2. Insert customers.
3. Insert credit applications with valid customer, branch, and decision-actor references.
4. Insert one-to-one client, credit-request, and project snapshots when their status permits them.
5. Insert documents and validation history.
6. Insert appointments using an existing branch and valid capacity/attempt rules.
7. Insert report messages, notifications, OTP history, and audit/security activity.
8. Commit the chunk, materialize requested fixtures, update its checkpoint, then continue.

Foreign-key integrity remains enabled throughout generation.

## Generated and intentionally excluded entities

### Generated

- `staff_users`: synthetic branch staff plus the internal actors needed for decisions and audit trails.
- `users`: synthetic customers with non-real names, reserved test email identities, Tunisian-style test phone numbers, and valid account states.
- `credit_applications`: non-uniform state-machine snapshots spread across branches and time.
- `clients`: application-scoped identity snapshots.
- `credit_requests`: application numbers, consistent identity snapshots, dates, currency, totals, and EQP/FDR/AMG/CHP breakdowns.
- `projects`: Tunisian-style governorates, delegations, addresses, locations, coordinates, activities, costs, financing, revenue, and expenses.
- `documents`: allowed document types, unique synthetic paths, storage metadata, and controlled AI-verification outcomes.
- `validation_steps`: passed and controlled failed validation attempts.
- `appointments`: branch-aware proposals and decisions.
- `report_messages`: customer/staff messages with valid sender ownership.
- `app_notifications`: customer notification activity with valid polymorphic owners and deduplication keys. Reusable factories also support isolated staff-notification test cases.
- `otp_codes`: hashed, non-usable test codes and controlled expiry/consumption cases.
- `audit_logs`: customer, staff, authentication, state-transition, security, and osquery activity.

### Reused, reconciled, or excluded

- `branches` are required existing reference data. The command fails clearly when no branch exists.
- `application_number_counters` remain compatible with application/client number generation. Synthetic natural keys are deterministic and collision-safe.
- `personal_access_tokens` are not generated. A stress dataset must not create millions of usable login tokens.
- Password-reset tokens, sessions, cache rows, queue jobs, job batches, and failed jobs are not generated. They test infrastructure lifecycle, not core banking history.
- No osquery, telemetry, security-event, vulnerability, or scan-result table exists. The generator does not invent one.

Security-center telemetry remains live host data. Synthetic security activity is represented through `audit_logs`, including controlled security and `audit.osquery_query` actions. Test IP addresses use documentation-only ranges, not routable customer addresses.

## Credit-application state rules

The only valid application statuses are the constants in `App\Models\CreditApplication`:

```text
DRAFT
STEP_1_COMPLETED
STEP_2_COMPLETED
STEP_3_COMPLETED
READY_FOR_VALIDATION_1
VALIDATION_1_COMPLETED
VALIDATION_2
FINAL_LOCKED
SUBMITTED
STAFF_APPROVED
STAFF_REJECTED
APPROVED
REJECTED
APPOINTMENT_PROPOSED
APPOINTMENT_CONFIRMED
APPOINTMENT_LOCKED
CANCELLED
```

`ADMIN_APPROVED` and `VALIDATION_2_COMPLETED` are not schema values. Administrative approval is `APPROVED`.

The normal customer progression is:

```text
DRAFT
STEP_1_COMPLETED
STEP_2_COMPLETED
STEP_3_COMPLETED
READY_FOR_VALIDATION_1
VALIDATION_1_COMPLETED
VALIDATION_2
FINAL_LOCKED
SUBMITTED
```

Review and appointment branches then follow `CreditApplicationStateMachine`:

- `SUBMITTED` may become `STAFF_APPROVED`, `STAFF_REJECTED`, or staff-cancelled.
- `STAFF_APPROVED` may receive final `APPROVED` or `REJECTED` review and valid appointment/cancellation outcomes.
- `APPROVED` may proceed into appointment states.
- Appointment reproposals and decisions remain within the transitions accepted by the state machine.
- `STAFF_REJECTED`, `REJECTED`, and `CANCELLED` are terminal.
- Customer cancellation is allowed only on valid pre-lock stages. Once finally locked, customer cancellation is not generated.

For large batches, the generator builds a prevalidated transition plan instead of creating millions of Eloquent models and replaying HTTP requests. This is a performance optimization, not a relaxation of domain rules: each snapshot must have a reachable status, compatible children, valid actor role, and corresponding synthetic history.

## Entity invariants

### Identity consistency

For one application, client, credit-request, and project identity snapshots use the same synthetic person identifier, name, PID type, and PID number where those columns overlap. An edge-case profile may intentionally create a mismatch only when it is explicitly marked as a controlled validation or AI-review case.

### Branch isolation

- Every routed application references an existing branch.
- Restricted staff actors belong to the application's branch.
- Admin and security actors may remain global according to existing role rules.
- Appointments use the same routed branch unless a documented scheduling rule selects another valid existing branch.
- Unassigned applications are limited to controlled edge cases and are never assigned to branch-restricted staff.

### Financial consistency

- Currency is `TND` unless an existing rule explicitly permits another code.
- Money is generated to three decimal places, matching `DECIMAL(14,3)` columns.
- EQP, FDR, AMG, and CHP components are non-negative and sum to `montant_global_sollicite`.
- Project cost, personal investment, and requested financing remain arithmetically consistent.
- Revenue and expenses follow non-uniform but plausible synthetic ranges.
- Floating-point arithmetic is not used to persist money; generation works in integer millimes and formats the final decimal value.

The document configuration contains an EPR category, but the database has no `montant_epr` column. EPR may appear as an allowed document category; it must not be invented as a credit-request amount field.

### Documents

- `document_type` comes from `config/credit_documents.php`.
- Applications that reach validation have at least one supporting document, as required by `CreditApplicationValidationService`.
- `disk_path` is globally unique, including soft-deleted rows.
- AI fields describe controlled synthetic outcomes only.
- No external AI call is needed to construct the dataset.
- Materialized files contain an unmistakable synthetic-test marker and no personal information.

### Appointments

- Attempt numbers are between 1 and `Appointment::MAX_ATTEMPTS`, currently 5.
- Dates use working days and valid branch slot times.
- Scheduled rows respect branch daily capacity.
- Proposed appointments have no decision time; accepted, rejected, and cancelled appointments have one.
- Application appointment status and latest appointment outcome agree.
- The generator does not rely on a database uniqueness constraint for attempts or slots because none exists; it enforces those invariants before batch insertion.

### Reports and messages

- Customer messages set `sender_type=customer`, use the application's owner as `user_id`, and leave `staff_user_id` null.
- Staff messages set `sender_type=staff`, reference a valid authorized staff actor, and leave `user_id` null.
- Big-data report messages do not create attachments; reusable factories can create isolated synthetic attachment metadata for feature tests.

### Notifications and audit activity

- Notification polymorphic owner type and ID always resolve to an existing User or StaffUser.
- Non-null deduplication keys remain unique for owner/type/key combinations.
- Audit actors and application references exist when present.
- State-change audit payloads use reachable previous and new statuses.
- Controlled authentication failures may be anonymous because the real schema permits null actors.
- Audit and OTP history use synthetic reserved IP/contact data and non-usable secrets.

## Analytics distributions

The generator uses deterministic weighted distributions rather than uniform random values. A fixed seed selects the same choices every run.

The dataset includes:

- Multiple years and months, with seasonal volume changes rather than identical daily counts.
- Weighted branch volumes, including high-, medium-, and low-volume branches.
- More ordinary application amounts and fewer low/high boundary values.
- Meaningful populations of drafts, active validation, submitted, approved, rejected, cancelled, and appointment states.
- Repeat applicants mixed with customers who have one or no application.
- Appointment proposals, acceptances, rejections, and bounded retry histories. Isolated factories cover cancelled-appointment tests.
- Read and unread notifications.
- Customer and staff report activity.
- Authentication, review, document, appointment, security, and osquery audit actions.

Current application-status weights are defined in `backend/config/synthetic_data.php` and total 100:

| Status | Weight |
| --- | ---: |
| `DRAFT` | 12 |
| `STEP_1_COMPLETED` | 6 |
| `STEP_2_COMPLETED` | 6 |
| `STEP_3_COMPLETED` | 1 |
| `READY_FOR_VALIDATION_1` | 10 |
| `VALIDATION_1_COMPLETED` | 7 |
| `VALIDATION_2` | 2 |
| `FINAL_LOCKED` | 1 |
| `SUBMITTED` | 15 |
| `STAFF_APPROVED` | 10 |
| `STAFF_REJECTED` | 6 |
| `APPROVED` | 1 |
| `REJECTED` | 6 |
| `APPOINTMENT_PROPOSED` | 6 |
| `APPOINTMENT_CONFIRMED` | 7 |
| `APPOINTMENT_LOCKED` | 2 |
| `CANCELLED` | 2 |

Recent years receive more records through weights `45, 25, 15, 8, 4, 2, 1`. Month weights are `8, 7, 8, 9, 11, 12, 9, 7, 8, 9, 7, 5`, producing visible seasonality while retaining activity throughout the year.

Controlled edge cases are bounded so they do not overwhelm normal dashboard trends. Examples include exact monetary boundaries, zero optional financing categories, near-limit child counts, leap dates, nullable optional identity fields, maximum appointment attempts, failed document verification, expired/consumed OTPs, security audit events, unread notifications, and pre-submission applications without an assigned branch.

## Reproducibility and deterministic keys

`--seed` controls all generated choices. Its default is `20260823`; generated dates use the fixed anchor `2026-08-23 12:00:00`, so rerunning later does not shift the dataset. Reproducibility requires the same:

- source revision and migrations;
- generator version;
- seed;
- resolved customer/application counts;
- document policy;
- existing branch set and stable branch ordering.

The command derives a 12-character `runKey` from SHA-256 of the configured namespace, profile, customer count, application count, and seed. Externally unique synthetic values are derived from that run key and logical row ordinal instead of process-global Faker uniqueness state. Customer emails use `syn-<runKey>-c<ordinal>@synthetic.bts.invalid`; phone numbers combine `+216`, a run-key namespace, and a customer ordinal. Application-scoped identifiers use `SYN-CL-<RUNKEY>-<customer>-<history>`, `SYN-CR-...`, and `SYN-PJ-...`. Chunk boundaries therefore do not change record identity.

Document paths use `synthetic-data/<runKey>/c<customer>/a<history>/<type>-<index>.txt`. Notification deduplication keys start with `syn-<runKey>`. Notification JSON carries `synthetic=true`, audit state carries the same marker, and synthetic HTTP activity uses `192.0.2.0/24` plus an unmistakable generator user agent.

The same seed reproduces the same logical dataset. Database-generated primary-key values may differ when the target already contains rows, so tests compare synthetic natural keys and normalized business fields, not raw auto-increment IDs.

## Checkpoints and restart behavior

Long runs persist a checkpoint under `backend/storage/app/private/synthetic-data/checkpoints`. Its stable filename is a 24-character SHA-256 prefix derived from the Laravel connection name, database name, run key, customer/application counts, and document mode. A sibling `.lock` file prevents concurrent execution of the same workload.

The payload identity additionally hashes the migration history, complete ordered branch snapshot, generator version, selected profile, status/date distributions, and seed. It also records a synthetic-data marker, completed customer ordinal, completion flag, checksum, and update time. Writes use a temporary file, filesystem flush where available, and atomic rename. Malformed, mismatched, or checksum-invalid checkpoints stop the run instead of silently restarting from zero.

Checkpoint updates happen only after a database chunk commits. On restart with the same fingerprint, the command resumes from the last committed range. Deterministic unique keys make replay safe if the process stops after a database commit but before its checkpoint is replaced.

A checkpoint whose persisted identity or checksum differs from the selected workload is never silently reused. Migration, branch, generator-version, or distribution changes therefore stop an unsafe mixed-version resume even though the stable checkpoint filename remains discoverable.

Do not manually edit a checkpoint. Preserve it with the test database when moving or restoring a partially generated dataset.

## Performance design

Small profile data can use factories for expressiveness. Large profiles use a streaming batch pipeline:

- Use order-independent seeded PHP random streams, so chunk boundaries and restarts do not change logical records.
- Generate one bounded chunk in memory.
- Use `DB::table()->insert()` or equivalent bulk inserts for compatible tables.
- Commit per chunk rather than holding one transaction for the entire run.
- Preserve foreign keys and indexes.
- Avoid model observers, notifications, broadcasts, AI verification, and queue dispatch while constructing equivalent synthetic history explicitly.
- Resolve branch and staff lookup maps once per run.
- Avoid per-record relationship queries.
- Free chunk arrays after commit.
- Report phase progress, rows per second, elapsed time, memory use, and current-run inserted totals.

Chunk selection is workload-dependent:

- Smaller chunks reduce memory and transaction-log pressure.
- Larger chunks improve throughput until packet size, lock time, or storage latency becomes limiting.
- Start conservatively on XAMPP/Windows and increase only after observing memory, MariaDB logs, and disk usage.
- Large and massive profiles should use metadata-only documents unless filesystem behavior is the benchmark target.

Run analytics only after generation finishes when measuring raw insert throughput. Run `ANALYZE TABLE` through an approved database-maintenance workflow before evaluating query plans on a newly loaded large dataset.

## Production protection

Production, staging, and all custom deployed environments remain blocked unless every factor below is present. Local and automated test environments are the only default-safe execution environments.

1. Set `SYNTHETIC_DATA_ALLOW_PRODUCTION=true` in the protected runtime environment.
2. Set `SYNTHETIC_DATA_PRODUCTION_TOKEN` to a secret of at least 32 characters. With `--allow-production`, the command prompts for the matching secret without echoing it; non-interactive execution cannot bypass this gate.
3. Pass both `--allow-production` and the exact option `--production-confirmation=GENERATE_SYNTHETIC_TEST_DATA`.

Missing, short, mismatched, or malformed values cause a refusal before any insert. The target connection, database name, environment, requested counts, and prominent synthetic-data warning are shown before generation starts.

The token is deliberately never accepted as a command-line option, so it cannot leak through process listings or shell history. Prefer an isolated maintenance host and organization-approved secret injection. Production permission should be temporary and removed immediately after an authorized run.

These gates reduce accidental execution; they do not make mixed production/test data a recommended design.

## Document storage policy

### Metadata-only mode

This mode inserts valid document metadata and unique synthetic paths but does not create payload files. It is suitable for database statistics, query plans, dashboards, and relational stress tests. Any endpoint that opens the file may correctly report that the synthetic fixture is absent.

### Materialized mode

This mode creates small text fixtures beneath the application's configured private document storage using the same relative synthetic namespace recorded in `disk_path`. Files contain only a synthetic marker and deterministic fixture identifier.

Materialized mode must:

- refuse paths outside the configured storage root;
- write files through the configured private Laravel storage disk;
- never overwrite a non-synthetic file;
- keep metadata size and MIME fields consistent with content;
- advance the checkpoint only after every requested fixture write succeeds, so a replay repairs a commit-to-checkpoint interruption;
- report total file count and bytes.

Database backup alone does not preserve materialized fixtures. Back up or discard the synthetic storage namespace together with its test database.

## Safe reset and cleanup

The command deliberately has no reset option.

Preferred cleanup is to discard and recreate the dedicated test database and its synthetic storage namespace through the team's approved database/environment provisioning workflow. This provides a clear boundary and avoids accidental deletion of unrelated rows.

If a mixed database must be cleaned manually:

1. Stop all writers and take a verified backup.
2. Confirm the database is non-production and identify the exact generator run from its checkpoint and deterministic synthetic namespace.
3. Produce and review counts for every affected table before deleting anything.
4. Remove children before parents in the reverse dependency order: audit/notification/message/appointment/validation/document rows, one-to-one application details, applications, then synthetic customers/staff.
5. Remove only materialized files proven to be inside the synthetic storage namespace.
6. Re-run FK validation, relationship tests, and dashboard sanity checks.
7. Retain the reviewed cleanup record and backup until verification finishes.

No broad SQL deletion, wildcard filesystem deletion, or foreign-key disabling belongs in this document. Never infer synthetic ownership from creation date alone.

## Custom profiles

Prefer explicit count overrides for one-off work:

```text
php artisan bts:generate-data --profile=small --customers=10000 --applications=16000 --seed=20260823 --chunk=2000 --metadata-only-documents
```

For a reusable profile, add a named immutable profile to `backend/config/synthetic_data.php` and cover it with tests. Supported profile keys are:

- customer and application counts;
- date horizon in years;
- recommended chunk size;
- default document policy;
- optional `status_weights`, `year_weights`, and `month_weights` overrides.

Application histories are derived exactly from the customer/application ratio. Branch skew, financial bands, related-record ratios, and controlled edge cases are generator algorithms, not profile keys. Changing those algorithms requires tests plus a `generator_version` bump so an old checkpoint cannot resume with mixed semantics.

Profile validation must reject negative counts, unsupported statuses, impossible ratios, empty branch data, chunk sizes outside safe bounds, and counts beyond PHP/driver integer limits. Do not add columns or statuses merely to support a data profile.

## XAMPP and MariaDB notes

The Laravel backend may run through XAMPP PHP while MariaDB runs separately. In the current local layout, the newer MariaDB service uses `127.0.0.1:3307`; XAMPP's bundled database may still occupy `3306`. The generator uses Laravel's configured connection, not the database selected in phpMyAdmin.

Before a large run:

- Verify `backend/.env` points to the intended non-production database and port.
- Confirm MariaDB is running and Laravel can connect.
- Check free disk space for database files, binary logs, temporary files, and optional materialized documents.
- Back up important local data and test restoration.
- Keep InnoDB foreign-key integrity enabled.
- Monitor memory, disk latency, transaction-log growth, and server errors.
- Avoid running the massive profile on the same instance used for interactive development.

phpMyAdmin is useful for inspecting counts and samples but is not the generator runtime. Closing phpMyAdmin does not stop MariaDB or an Artisan command.

## Verification

Automated coverage for this subsystem must verify:

- production refusal and all three override factors;
- exact profile/override resolution;
- reproducibility with a fixed seed;
- restart from checkpoints and safe replay after an interrupted chunk;
- valid foreign keys and polymorphic owners;
- one-to-one application relationships;
- reachable application states and compatible child records;
- identity consistency across client/request/project rows;
- amount and financing equations;
- document requirements and allowed types;
- appointment attempts, weekdays, slots, capacity, and outcomes;
- branch isolation for staff decisions and messages;
- report sender ownership;
- reserved synthetic contact/IP data;
- no real tokens, sessions, jobs, or invented tables;
- metadata-only and materialized document policies.

After each run, retain the command summary with the benchmark result. A performance result without profile, seed, counts, chunk size, document policy, database version, hardware, and source revision is not reproducible.

## Phase 5 real API load testing

The bulk generator remains the source for deterministic analytics datasets. It is not used to fake successful API workflows. The companion system in `tools/synthetic-load-tester` creates only isolated synthetic identities, then drives login, application steps, uploads, state transitions, staff/admin decisions, appointment scheduling, notifications, chat, and operational probes through the real Laravel API.

Local runs create a fresh MariaDB database whose name and signed marker satisfy the dedicated load-test safety gate. The generator may optionally prefill that database with `small` or `medium`; ordinary development and production databases cannot expose the test status endpoint or use the load-test OTP provider. See `tools/synthetic-load-tester/README.md` for commands, scenarios, thresholds, reports, and cleanup behavior.

Measured Phase 5 validation uses metadata-only documents for prefilled datasets. `large` and `massive` are not run automatically: extrapolate only from a recorded medium measurement, then validate on an isolated host with adequate disk, memory, transaction-log capacity, and monitoring. Estimated duration or storage is planning data, never a capacity guarantee.
