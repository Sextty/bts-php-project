# BTS Bank — Notification and Discussion Workflow Report

**Date:** 2026-08-26  
**Scope:** final-decision notifications, appointment discussion eligibility, Staff visibility, Client turn-taking, Reverb durability.  
**Result:** PASS for application behavior and isolated E2E; ACTION REQUIRED for the existing local queue backlog.

## 1. Root causes

### BUG 1 — final-decision email not received

The final-decision service, notification record, delivery record, deduplication key and mail channel were correct. The development environment uses `QUEUE_CONNECTION=database`, but no queue worker was running.

Observed before correction:

- 45 ready jobs, none reserved;
- oldest ready job about 8.8 days old;
- 28 pending async-outbox events;
- no failed jobs;
- the sampled final-decision notification and email delivery both remained `pending`;
- Gmail SMTP transport initialization/authentication succeeded without sending a message.

The application did not falsely mark the email as delivered: the job was never consumed. `QUEUE_WORKER_HEALTH_REQUIRED` is now `true` in `.env.example` and in the local ignored `.env`, so readiness reports a missing heartbeat instead of treating an absent worker as optional. No historical job was executed automatically because doing so could send stale messages to old development recipients.

### BUG 2 — discussion opened too early

`CreditApplication::isReportOpen()` treated `APPOINTMENT_CONFIRMED` as eligible. The Staff query also listed confirmed applications, and two Client appointment views embedded `ReportChat` immediately after confirmation. Confirmation is no longer an appointment-escalation trigger.

The appointment escalation rule is now:

| Successful customer reschedules | Discussion |
|---:|---|
| 0 | closed |
| 1 | closed |
| 2 | closed |
| 3 | closed |
| 4 | open |

Valid historical conversations remain reachable through existing `report_messages`. `CANCELLED` and `APPOINTMENT_LOCKED` legacy cases remain supported.

### BUG 3 — fresh eligible discussion missing from Staff

The current backend already contained the four-reschedule predicate, and the Staff frontend did not remove API records. A fresh isolated application became visible immediately after successful reschedule #4 for Staff assigned to the application branch and remained invisible to another branch.

The development record reported as missing belonged to another branch for which no matching active operational Staff account was found. This was branch isolation, not a rendering failure. The old records shown to the logged-in branch included legacy conversations and incorrectly eligible confirmed applications. Removing `APPOINTMENT_CONFIRMED` from the query removes those false positives without widening scope.

Application `updated_at` is refreshed by the same-state `APPOINTMENT_PROPOSED` state-machine write after each successful reschedule, so a newly escalated case sorts ahead of older history. A regression test also proves that it appears on page 1 with more than 25 historical cases.

### BUG 4 — false “already sent” state

`CreditApplication::reportMessages()` applied `created_at ASC` by default. Controllers then appended `latest('id')`; the first ordering still won, so the oldest customer message could be interpreted as the current turn even after Staff replied.

The relationship is now unordered. Every read specifies its required order explicitly. The turn check uses the real latest persisted message by descending ID inside a transaction that locks the application row. Customer and Staff writes use the same row lock, preventing concurrent duplicate customer turns.

The Client component also kept local messages when its `applicationId` or asynchronous initial payload changed. It now remounts a chat session using the application ID and initial-message version, preventing a previous dossier from producing a false waiting banner.

## 2. Actual discussion storage lifecycle

There is no `reports` table and no empty thread row.

1. Eligibility is derived from `credit_applications`, appointments, statuses and existing historical messages.
2. Opening `GET /report/messages` creates no database row.
3. The first human interaction creates the first `report_messages` row.
4. Staff `/reports` can list an eligible four-reschedule case before the first message; it does not create a fake message.
5. Reverb/outbox delivery is secondary. Refreshing the page reads the database and remains correct when Reverb is unavailable.

## 3. Isolated reproduction evidence

A disposable MariaDB schema was used with one fully synthetic application throughout diagnosis:

| Field | Value |
|---|---|
| application ID / number | `1` / `DIAG-20260826-001` |
| customer ID | `1` |
| assigned branch / Staff branch | `1` / `1` |
| other Staff branch | `2` |
| final workflow status | `APPOINTMENT_PROPOSED` |
| final appointment ID/status | `5` / `proposed` |
| reschedule count / remaining | `4` / `0` |
| report table/thread ID | none (derived lifecycle) |
| message count before interaction | `0` |

At counts 0, 1, 2 and 3, Client report GET returned `404 REPORT_NOT_OPEN` and assigned Staff did not receive the record. At count 4, Client GET returned 200 with zero messages, assigned Staff received the record, and other-branch Staff did not. The temporary database contained only one application and two diagnostic messages and was dropped after validation. Development data was not deleted or reseeded.

## 4. Endpoints and files

Affected endpoints:

- `GET|POST /api/applications/{application}/report/messages`
- `GET /api/staff/reports`
- `GET|POST /api/staff/reports/{application}/messages`
- `POST /api/staff/applications/{application}/approve`
- `POST /api/staff/applications/{application}/admin-approve`
- `POST /api/staff/applications/{application}/admin-reject`
- `GET /api/notifications`
- private channel `application.{id}.report`

Changed files in this correction:

- `backend/app/Models/CreditApplication.php`
- `backend/app/Http/Controllers/CreditApplication/ReportController.php`
- `backend/app/Http/Controllers/Staff/ReportController.php`
- `backend/.env.example`
- local ignored `backend/.env`
- `client/components/report-chat.tsx`
- `client/app/appointments/page.tsx`
- `client/app/applications/[id]/appointment/page.tsx`
- `backend/tests/Feature/CreditApplication/ReportChatTest.php`
- `backend/tests/Feature/Health/HealthEndpointTest.php`
- `e2e/04-admin-approval.spec.ts`

No migration, table, global UI redesign, permission relaxation or historical-data rewrite was introduced.

## 5. Final-decision email guarantees

Existing notification tests prove:

- Staff approval creates no final-decision email or appointment;
- final Admin approval creates one `admin.approved` notification after the automatic appointment, including application reference, agency and appointment time;
- replayed Admin approval is rejected and does not duplicate the notification/email;
- Admin rejection creates one `admin.rejected` notification, no appointment, and does not expose the internal rejection reason;
- transactional outbox rollback, retry and deduplication behavior remains intact.

Mail failure remains outside the final-decision database transaction, so it cannot roll back the decision or create another appointment. A worker must run continuously for queued delivery.

## 6. Authorization and Reverb

- `accessibleToStaff()` remains in the Staff list query.
- Direct access from another branch remains 403.
- An assigned-branch fresh case is returned after count 4; another branch receives neither the list item nor direct message access.
- Private-channel authorization tests pass.
- Playwright proves the customer message reaches Staff live and the Staff reply reaches Client live.
- Database/API refresh remains the source of truth; a Reverb outage does not lose the message because the message and outbox event are committed first.

## 7. Validation results

| Validation | Result |
|---|---|
| Focused workflow/security/notification tests | PASS — 89 tests, 448 assertions |
| Full Laravel suite | PASS — 443 tests, 3,295 assertions; 3 dedicated MariaDB concurrency tests skipped by the default runner |
| PHP Pint (changed backend files) | PASS |
| Client lint / TypeScript / production build | PASS / PASS / PASS |
| Staff lint / TypeScript / production build | PASS / PASS / PASS |
| Admin lint / TypeScript / production build | PASS / PASS / PASS |
| Full isolated Playwright on disposable MariaDB | PASS — 10/10 in 4.8 minutes |
| SMTP transport startup/authentication (no message sent) | PASS |
| Local operations readiness | DEGRADED as intended — worker heartbeat missing, 45 ready jobs, Reverb unavailable |

The E2E acceptance path proves: Admin approval notification exists, no early escalation, counts 4→3→2→1→0, fifth change rejected, empty composer enabled, consecutive Client message blocked, assigned Staff sees the fresh case, Staff replies, Client can send again, and cross-branch access remains blocked.

## 8. Operational action and remaining limitations

1. The existing 45-job development backlog must be reviewed before starting a worker because it may contain stale external notifications. It was deliberately not drained by this correction.
2. After backlog review, run a persistent worker from `backend`:

   ```powershell
   C:\xampp\php\php.exe artisan queue:work --sleep=1 --tries=3 --backoff=5 --timeout=90
   ```

3. Run Reverb separately for live updates; it is not required for database durability.
4. SMTP authentication was verified, but no real mailbox delivery/open was asserted to avoid sending unsolicited messages. Provider acceptance, spam filtering and mailbox placement remain external operational concerns.
5. The schema has customer/staff sender roles but no distinct `system` message type. Current system announcements are Staff-authored records; a test proves such an announcement does not block the Client's first message. A future explicit message type would improve semantics but is not required for this minimal correction.

## 9. Conclusion

The application-level workflow is corrected and validated end-to-end without weakening branch isolation, appointment locking, audit logging, queue durability or Reverb authorization. New final-decision emails will remain queued until a worker is intentionally started. Therefore the software correction is **PASS**, while local external delivery remains **operator action required** until the stale queue is reviewed and the persistent worker is running.
