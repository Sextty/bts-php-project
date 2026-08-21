<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the post-approval appointment cycle: propose a branch + slot, let the customer
 * accept/reject, re-propose on rejection up to Appointment::MAX_ATTEMPTS, then lock for staff to
 * take over manually. Kept separate from CreditApplicationReviewService (which owns the
 * SUBMITTED→APPROVED review stage) — this is a distinct concern that happens to be triggered by
 * that service's adminApprove(), not a natural extension of it.
 */
class AppointmentSchedulingService
{
    public function __construct(
        private readonly BranchMatchingService $branchMatching,
        private readonly AuditLogService $auditLog,
        private readonly CreditApplicationStateMachine $stateMachine,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The actor on the first proposal is the approving admin (APPROVED → APPOINTMENT_PROPOSED
     * is an admin transition in the machine); on re-proposals it's the customer (the
     * APPOINTMENT_PROPOSED → APPOINTMENT_PROPOSED loop).
     */
    public function proposeNext(CreditApplication $application, User|StaffUser $actor, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $previous = $application->latestAppointment();
        $attemptNumber = $previous ? $previous->attempt_number + 1 : 1;

        if ($attemptNumber > Appointment::MAX_ATTEMPTS) {
            throw new ApiException(ApiErrorCode::MaxAttemptsExceeded, 'No more appointment proposals are available.', status: 409);
        }

        // Same branch every attempt — the project's location doesn't change between them, so
        // re-matching would just do redundant work (and risk picking a different branch if the
        // matching data changed mid-cycle, which would be confusing for the customer). The
        // branch is whatever was persisted at submission; matching only as a fallback for
        // applications that predate that routing (branch_id null).
        $branch = $previous?->branch
            ?? Branch::find($application->branch_id)
            ?? $this->branchMatching->findForApplication($application);

        $appointment = DB::transaction(function () use ($application, $branch, $attemptNumber) {
            $lockedBranch = Branch::query()
                ->where('id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $slot = $this->findNextSlot($lockedBranch, application: $application);

            return Appointment::create([
                'credit_application_id' => $application->id,
                'branch_id' => $lockedBranch->id,
                'attempt_number' => $attemptNumber,
                'scheduled_date' => $slot['date'],
                'scheduled_time' => $slot['time'],
                'status' => Appointment::STATUS_PROPOSED,
                'is_auto_scheduled_future' => $slot['is_auto_scheduled_future'],
            ]);
        });

        $this->stateMachine->apply($application, CreditApplication::STATUS_APPOINTMENT_PROPOSED, $actor, ip: $ip, userAgent: $userAgent);

        $this->auditLog->log(
            'credit_application.appointment_proposed',
            $application->user,
            newState: [
                'attempt' => $attemptNumber,
                'branch_id' => $branch->id,
                'date' => (string) $appointment->scheduled_date,
                'time' => $appointment->scheduled_time,
                'is_auto_scheduled_future' => $appointment->is_auto_scheduled_future,
            ],
            ipAddress: $ip,
            userAgent: $userAgent,
            application: $application,
        );

        // First proposal = appointment.created; every re-proposal after a rejection =
        // appointment.changed. Distinct types, so both can exist on the same application
        // without tripping the dedupe index.
        $this->notifications->notifyUser(
            $application->user,
            $attemptNumber === 1 ? 'appointment.created' : 'appointment.changed',
            $attemptNumber === 1 ? 'Appointment proposed' : 'New appointment proposed',
            sprintf(
                'A new appointment has been proposed at %s on %s at %s (proposal %d of %d).',
                $branch->name,
                $appointment->scheduled_date->format('Y-m-d'),
                $appointment->scheduled_time,
                $attemptNumber,
                Appointment::MAX_ATTEMPTS,
            ),
            ['application_id' => $application->id, 'appointment_id' => $appointment->id],
            dedupeKey: 'appointment-'.$appointment->id,
        );

        return $appointment;
    }

    public function accept(CreditApplication $application, Appointment $appointment, User $actor, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $this->assertCurrentAndProposed($application, $appointment);

        DB::transaction(function () use ($application, $appointment, $actor, $ip, $userAgent) {
            $appointment->update(['status' => Appointment::STATUS_ACCEPTED, 'decided_at' => now()]);
            $this->stateMachine->apply($application, CreditApplication::STATUS_APPOINTMENT_CONFIRMED, $actor, ip: $ip, userAgent: $userAgent);
        });

        $this->auditLog->log(
            'credit_application.appointment_accepted',
            $application->user,
            newState: ['attempt' => $appointment->attempt_number],
            ipAddress: $ip,
            userAgent: $userAgent,
            application: $application,
        );

        return $appointment->fresh();
    }

    /**
     * Rejects the current proposal. If attempts remain, immediately proposes the next one —
     * the customer never has to take a separate action to ask for a new time. On the 3rd
     * rejection, locks the application for staff instead of proposing again.
     */
    public function reject(CreditApplication $application, Appointment $appointment, User $actor, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $this->assertCurrentAndProposed($application, $appointment);

        DB::transaction(function () use ($application, $appointment, $actor, $ip, $userAgent) {
            $appointment->update(['status' => Appointment::STATUS_REJECTED, 'decided_at' => now()]);

            $this->auditLog->log(
                'credit_application.appointment_rejected',
                $application->user,
                newState: ['attempt' => $appointment->attempt_number],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $application,
            );

            if ($appointment->attempt_number >= Appointment::MAX_ATTEMPTS) {
                $this->stateMachine->apply($application, CreditApplication::STATUS_APPOINTMENT_LOCKED, $actor, ip: $ip, userAgent: $userAgent);

                $this->auditLog->log(
                    'credit_application.appointment_locked',
                    $application->user,
                    newState: ['reason' => 'max_attempts_exceeded'],
                    ipAddress: $ip,
                    userAgent: $userAgent,
                    application: $application,
                );

                return;
            }

            $this->proposeNext($application, $actor, $ip, $userAgent);
        });

        return $appointment->fresh();
    }

    /**
     * Finds the earliest available slot for the branch on working days (Mon-Fri).
     * Automatically rolls forward to subsequent working days when earlier days reach capacity.
     *
     * @return array{date: string, time: string, is_auto_scheduled_future: bool}
     */
    public function findNextSlot(Branch $branch, ?Carbon $from = null, ?CreditApplication $application = null): array
    {
        $slotTimes = $branch->slotTimes();

        // Earliest possible appointment date is the next working day.
        $date = ($from ?? Carbon::tomorrow())->copy();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        $initialWorkingDay = $date->copy();

        // Bounded search: a branch that's somehow permanently full would otherwise loop forever.
        for ($i = 0; $i < 365; $i++) {
            if ($date->isWeekend()) {
                $date->addDay();
                continue;
            }

            $occupiedSlots = Appointment::query()
                ->where('branch_id', $branch->id)
                ->whereDate('scheduled_date', $date)
                ->whereNotIn('status', [Appointment::STATUS_REJECTED, Appointment::STATUS_CANCELLED])
                ->whereHas('creditApplication', function ($q) {
                    $q->where('status', '!=', CreditApplication::STATUS_CANCELLED);
                })
                ->lockForUpdate()
                ->pluck('scheduled_time')
                ->map(fn ($t) => strlen($t) === 5 ? $t.':00' : $t)
                ->all();

            $previouslyOfferedSlots = $application
                ? Appointment::query()
                    ->where('credit_application_id', $application->id)
                    ->whereDate('scheduled_date', $date)
                    ->pluck('scheduled_time')
                    ->map(fn ($t) => strlen($t) === 5 ? $t.':00' : $t)
                    ->all()
                : [];

            foreach ($slotTimes as $slot) {
                $normalizedSlot = strlen($slot) === 5 ? $slot.':00' : $slot;
                if (! in_array($normalizedSlot, $occupiedSlots, true) && ! in_array($normalizedSlot, $previouslyOfferedSlots, true)) {
                    return [
                        'date' => $date->toDateString(),
                        'time' => $slot,
                        'is_auto_scheduled_future' => $date->toDateString() !== $initialWorkingDay->toDateString(),
                    ];
                }
            }

            // All slots for this day are occupied or previously offered; move to next working day.
            do {
                $date->addDay();
            } while ($date->isWeekend());
        }

        throw new ApiException(ApiErrorCode::NoSlotsAvailable);
    }

    private function assertCurrentAndProposed(CreditApplication $application, Appointment $appointment): void
    {
        $latest = $application->latestAppointment();

        if (! $latest || $latest->id !== $appointment->id) {
            throw new ApiException(ApiErrorCode::NotCurrentAppointment);
        }

        if ($appointment->status !== Appointment::STATUS_PROPOSED) {
            throw new ApiException(ApiErrorCode::AppointmentAlreadyDecided);
        }
    }
}
