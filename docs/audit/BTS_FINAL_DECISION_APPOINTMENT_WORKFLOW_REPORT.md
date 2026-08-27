# BTS Bank — Final Decision & Appointment Workflow Report

Date: 2026-08-26  
Scope: final credit decision, customer notification, automatic appointment, four customer reschedules, and discussion escalation.

## Executive result

The workflow now treats Admin approval/rejection as the real final decision. Staff approval remains an intermediate review and does not send a final acceptance email. Final approval creates the first appointment automatically, starting on the next valid working day at the earliest configured free slot. Customers may complete four successful self-service changes; a fifth is rejected by the backend, while the current slot remains confirmable. At the limit, the existing authenticated report/chat is opened for the customer and the assigned branch.

No development records were deleted or reseeded. Branch isolation, the existing state machine, queued delivery, audit chaining, and the transactional scheduler were retained.

## Previous behavior and root causes

- `attempt_number` described appointment proposal history, including Staff-created proposals. It could not accurately represent customer-only reschedules.
- Notification configuration could emit an acceptance-style email at Staff approval, before the required Admin decision.
- Final decision messages did not consistently include the actual request reference and appointment details. `application_number` was not a stored attribute; the real reference is `credit_requests.n_demande`.
- The initial appointment existed in the approval path, but email orchestration could produce redundant messages.
- The report lifecycle opened at broad intermediate statuses and did not explicitly model escalation after four customer changes.
- Customer screens derived some appointment behavior from status/attempt history rather than a dedicated backend contract.
- During final E2E validation, the detailed appointment screen had inverted button disabling at the limit: modification remained enabled and confirmation was disabled. This was corrected; the API had already rejected the fifth change.

## Business rules implemented

### Final approval

1. Staff approval transitions only to the existing intermediate Staff-approved state.
2. Admin approval is the real final approval.
3. The approval transaction uses the existing state machine and transactional scheduling service.
4. A first appointment is created automatically for the assigned branch.
5. One idempotent `admin.approved` notification is queued. Its French email contains the actual dossier reference, agency, date, time, and authenticated portal link.
6. Repeated approval calls or queue retries do not create another final-decision notification.
7. Appointment creation and application approval are not rolled back by a later email-provider failure.

`staff.approved` is in-app only. `appointment.created` is also in-app only because the final approval email already contains the initial appointment, preventing duplicate customer emails.

### Final rejection

- Terminal Staff rejection and Admin rejection use distinct idempotency keys.
- Exactly one professional French rejection email is queued for a final rejection.
- The email contains the actual dossier reference but not internal Staff/Admin notes.
- Customer API resources return no internal `rejection_reason`; Staff resources retain authorized access.
- No appointment is created and customer appointment controls remain hidden.

### Scheduling algorithm

The existing configured slots are sorted chronologically. Starting from `approval date + 1 calendar day`, the scheduler:

1. skips invalid working days using the existing Monday–Friday rule;
2. locks the application and assigned branch in a deterministic order;
3. inspects configured slots from earliest to latest;
4. selects the first available exclusive branch slot;
5. advances to the next valid working day when all slots are occupied;
6. inserts the appointment inside the MariaDB transaction.

The first automatic appointment can never be on the approval date. No new hard-coded slot list was introduced.

### Concurrency strategy

- MariaDB transaction boundaries are preserved.
- Application, branch, and current appointment rows are locked with deterministic ordering.
- The scheduler rechecks occupancy while holding the branch scheduling lock.
- The unique `(credit_application_id, attempt_number)` constraint prevents duplicate attempt history.
- Dedicated MariaDB tests run competing scheduler and audit-chain writers in separate connections.

### Four-change counter

`appointments.reschedule_count` is the backend source of truth:

- automatic appointment: `0`;
- successful customer changes: `1`, `2`, `3`, `4`;
- maximum self-service changes: `4`;
- fifth request: HTTP `409`, code `APPOINTMENT_RESCHEDULE_LIMIT`.

The count is copied to the committed replacement appointment and increments only inside the successful transaction. Validation, authorization, occupied-slot errors, and rollbacks do not consume a change. Staff manual scheduling preserves the customer count and does not consume customer quota.

The API exposes `reschedule_count`, `max_reschedules`, `remaining_reschedules`, and `can_self_reschedule`. Both Client appointment screens use these fields. At zero, the modification button is disabled, the current proposal can still be confirmed, and a non-color-only warning is displayed.

### Discussion escalation and authorization

After `reschedule_count >= 4`, the existing application report/chat becomes available. No second chat implementation was created.

- The customer can open only a discussion owned by their application.
- Assigned-branch Staff can list and open the discussion through existing scope checks.
- Other branches remain denied.
- Existing Reverb authorization and outbox broadcasting are reused.
- A branch-scoped `appointment.escalated` notification informs Staff.

The report lifecycle now opens for confirmed/legacy-locked appointments, cancelled applications, an existing discussion, or the four-change escalation. It no longer opens merely for broad intermediate approval states.

## Audit events

The existing integrity-chained audit service records:

- existing final approval/rejection review events;
- `credit_application.final_decision_notification_queued`;
- `credit_application.final_decision_notification_sent`;
- existing automatic appointment creation;
- `credit_application.appointment_rescheduled`;
- `credit_application.appointment_reschedule_limit_reached`;
- `credit_application.discussion_escalated`.

No OTP, access token, email secret, full email body, or full sensitive payload is added to these events.

## Migration and existing data

Migration: `2026_08_26_120000_add_customer_reschedule_count_to_appointments.php`.

The new unsigned counter defaults to zero. MariaDB backfill interprets only earlier rejected appointment proposals for the same application as historical customer changes and caps the value at four. It does not blindly copy `attempt_number`, because Staff scheduling also increments that field. A chunked fallback keeps SQLite test migrations compatible.

Real development database validation:

| Measure | Before | After |
|---|---:|---:|
| Credit applications | 1,330 | 1,330 |
| Appointments | 647 | 647 |
| Rejected appointment history | 478 | 478 |
| Invalid counters above 4 | n/a | 0 |

Backfilled appointment distribution: count 0 = 208, 1 = 175, 2 = 135, 3 = 92, 4 = 37. The migration completed successfully on MariaDB without deleting or replacing development data.

## Main files

Backend:

- `backend/database/migrations/2026_08_26_120000_add_customer_reschedule_count_to_appointments.php`
- `backend/app/Models/Appointment.php`
- `backend/app/Models/CreditApplication.php`
- `backend/app/Services/AppointmentSchedulingService.php`
- `backend/app/Services/CreditApplicationReviewService.php`
- `backend/app/Jobs/DeliverNotificationJob.php`
- `backend/app/Services/Notifications/EmailNotificationChannel.php`
- `backend/app/Http/Resources/AppointmentResource.php`
- `backend/app/Http/Resources/CreditApplicationResource.php`
- `backend/app/Http/Controllers/Staff/ReportController.php`
- `backend/app/Enums/ApiErrorCode.php`
- `backend/config/services.php`

Client and E2E:

- `client/lib/api/appointments.ts`
- `client/lib/status-labels.ts`
- `client/app/applications/[id]/appointment/page.tsx`
- `client/app/appointments/page.tsx`
- `client/app/applications/[id]/page.tsx`
- `client/components/dashboard/appointments-summary.tsx`
- `e2e/04-admin-approval.spec.ts`
- `e2e/helpers.ts`

## Verification

| Check | Result |
|---|---|
| Focused workflow/authorization tests | PASS — 85 tests, 386 assertions |
| Full backend suite (dedicated MariaDB group excluded) | PASS — 430 tests, 3,228 assertions; 3 MariaDB-only tests skipped by design |
| MariaDB appointment + audit concurrency | PASS — 3 tests, 57 assertions |
| Fresh isolated MariaDB migration and seed | PASS |
| Real development MariaDB migration/data-count comparison | PASS |
| Client lint / TypeScript / production build | PASS / PASS / PASS |
| Staff lint / TypeScript / production build | PASS / PASS / PASS |
| Admin lint / TypeScript / production build | PASS / PASS / PASS |
| State machine and appointment locking regression | PASS |
| Staff branch visibility and cross-branch denial | PASS |
| Staff Analytics and Phase 6 scoped analytics | PASS |
| Reverb/report authorization tests | PASS |
| Playwright isolated full flow | PASS — 10/10 scenarios, 5.4 minutes |

The first two E2E attempts exposed stale selectors caused by the earlier intentional removal/renaming of Project upload controls. The helper now uploads the synthetic identity document through the existing authenticated API and uses the current validation navigation label. A later run passed 9/10 scenarios and exposed the genuine detailed-screen button-state defect described above; that defect was corrected before the final run.

## Remaining limitations

- Working-day logic currently models weekdays, not Tunisian public holidays; the project has no authoritative holiday calendar table/service.
- Email and Reverb delivery require healthy workers in asynchronous environments. Durable notification/outbox rows and retries preserve work while workers are offline, but delivery is delayed until workers resume.
- Staff can perform an authorized manual scheduling action after customer escalation; this intentionally does not reset or consume customer quota.
- Generated `.next-workflow` build artifacts from local validation are ignored by Git. They are not application source.

## Conclusion

Backend rules, migration integrity, concurrency, authorization, queued notification idempotency, frontend contracts, all three production builds, and the complete isolated Playwright workflow are validated. No unrelated feature or UI redesign was started in this correction batch.
