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
 * accept/reject, and re-propose after at most four committed customer changes. Once that quota
 * is reached the current proposal remains valid, self-service changes stop, and the existing
 * branch-scoped discussion becomes the escalation path. Kept separate from CreditApplicationReviewService (which owns the
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
        $appointment = DB::transaction(function () use ($application, $actor, $ip, $userAgent) {
            $lockedApplication = CreditApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Same branch every attempt. Matching is only a fallback for legacy unrouted rows.
            $previous = $lockedApplication->latestAppointment();
            $branch = $previous?->branch
                ?? Branch::find($lockedApplication->branch_id)
                ?? $this->branchMatching->findForApplication($lockedApplication);

            $lockedBranch = Branch::query()
                ->where('id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();

            [$appointment, $lockedApplication, $branch, $attemptNumber] = $this->proposeNextLocked($lockedApplication, $lockedBranch, $actor, $ip, $userAgent);
            $this->afterProposalCommitted($appointment, $lockedApplication, $branch, $attemptNumber, $ip, $userAgent);

            return $appointment;
        }, 5);

        return $appointment;
    }

    private function afterProposalCommitted(Appointment $appointment, CreditApplication $application, Branch $branch, int $attemptNumber, ?string $ip, ?string $userAgent): void
    {
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

        if ($appointment->rescheduleCount() > 0) {
            $rescheduleCount = $appointment->rescheduleCount();
            $this->auditLog->log(
                'credit_application.appointment_rescheduled',
                $application->user,
                newState: [
                    'appointment_id' => $appointment->id,
                    'reschedule_count' => $rescheduleCount,
                    'remaining_reschedules' => max(0, Appointment::MAX_RESCHEDULES - $rescheduleCount),
                    'branch_id' => $branch->id,
                ],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $application,
            );

            if ($rescheduleCount === Appointment::MAX_RESCHEDULES) {
                $this->auditLog->log(
                    'credit_application.appointment_reschedule_limit_reached',
                    $application->user,
                    newState: [
                        'appointment_id' => $appointment->id,
                        'reschedule_count' => $rescheduleCount,
                        'discussion_available' => true,
                    ],
                    ipAddress: $ip,
                    userAgent: $userAgent,
                    application: $application,
                );
                $this->auditLog->log(
                    'credit_application.discussion_escalated',
                    $application->user,
                    newState: ['reason' => 'appointment_reschedule_limit', 'branch_id' => $branch->id],
                    ipAddress: $ip,
                    userAgent: $userAgent,
                    application: $application,
                );
                $this->notifications->queueStaffAudience(
                    $application,
                    'appointment.escalated',
                    'Reprogrammation à traiter — '.$application->application_number,
                    'Le client a utilisé ses 4 changements de rendez-vous. La discussion avec son agence est disponible.',
                    [
                        'application_id' => $application->id,
                        'application_number' => $application->application_number,
                        'branch_id' => $branch->id,
                    ],
                    dedupeKey: 'appointment-reschedule-limit-'.$application->id,
                );
            }
        }
    }

    public function accept(CreditApplication $application, Appointment $appointment, User $actor, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $appointment = DB::transaction(function () use ($application, $appointment, $actor, $ip, $userAgent) {
            $lockedApplication = CreditApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertCurrentAndProposed($lockedApplication, $lockedAppointment);

            $lockedAppointment->update(['status' => Appointment::STATUS_ACCEPTED, 'decided_at' => now()]);
            $this->stateMachine->apply($lockedApplication, CreditApplication::STATUS_APPOINTMENT_CONFIRMED, $actor, ip: $ip, userAgent: $userAgent);

            $this->auditLog->log(
                'credit_application.appointment_accepted',
                $lockedApplication->user,
                newState: ['attempt' => $lockedAppointment->attempt_number],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $lockedApplication,
            );

            return $lockedAppointment;
        }, 5);

        return $appointment->fresh();
    }

    /**
     * Rejects the current proposal and immediately commits a replacement. The initial proposal
     * is attempt 1, so attempts 2..5 are exactly the four successful customer reschedules.
     * A fifth request is rejected before the current appointment is changed.
     */
    public function reject(CreditApplication $application, Appointment $appointment, User $actor, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $appointment = DB::transaction(function () use ($application, $appointment, $actor, $ip, $userAgent) {
            $lockedApplication = CreditApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $lockedBranch = Branch::query()->whereKey($appointment->branch_id)->lockForUpdate()->firstOrFail();
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertCurrentAndProposed($lockedApplication, $lockedAppointment);

            if (! $lockedAppointment->canSelfReschedule()) {
                throw new ApiException(ApiErrorCode::AppointmentRescheduleLimit);
            }

            $lockedAppointment->update(['status' => Appointment::STATUS_REJECTED, 'decided_at' => now()]);

            $this->auditLog->log(
                'credit_application.appointment_rejected',
                $lockedApplication->user,
                newState: ['attempt' => $lockedAppointment->attempt_number],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $lockedApplication,
            );

            $proposal = $this->proposeNextLocked($lockedApplication, $lockedBranch, $actor, $ip, $userAgent);
            $this->afterProposalCommitted($proposal[0], $proposal[1], $proposal[2], $proposal[3], $ip, $userAgent);

            return $lockedAppointment;
        }, 5);

        return $appointment->fresh();
    }

    /**
     * Authoritative manual scheduling path. Lock order is always application -> branch ->
     * appointments. Branch-restricted staff cannot reroute a dossier to another branch.
     */
    public function scheduleManual(
        CreditApplication $application,
        StaffUser $actor,
        int $branchId,
        string $date,
        string $time,
        bool $directConfirm = true,
        ?string $ip = null,
        ?string $userAgent = null,
    ): Appointment {
        return DB::transaction(function () use ($application, $actor, $branchId, $date, $time, $directConfirm, $ip, $userAgent) {
            $lockedApplication = CreditApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! $actor->canAccessApplication($lockedApplication)) {
                throw new ApiException(ApiErrorCode::Forbidden, 'This application is not handled by your branch.');
            }

            if ($actor->isBranchRestricted() && $actor->branch_id !== $branchId) {
                throw new ApiException(ApiErrorCode::Forbidden, 'Appointments must remain in your assigned branch.');
            }

            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            $appointments = Appointment::query()
                ->where('credit_application_id', $lockedApplication->id)
                ->orderBy('attempt_number')
                ->lockForUpdate()
                ->get();

            $scheduledDate = Carbon::parse($date, config('app.timezone'))->startOfDay();
            if ($scheduledDate->lt($this->businessToday()) || ! $this->isWorkingDay($scheduledDate)) {
                throw new ApiException(ApiErrorCode::NoSlotsAvailable, 'Appointments must use a current or future working day.', 409);
            }

            $normalizedTime = strlen($time) === 5 ? $time.':00' : $time;
            $desiredAppointmentStatus = $directConfirm ? Appointment::STATUS_ACCEPTED : Appointment::STATUS_PROPOSED;
            $latestActive = $appointments
                ->whereIn('status', [Appointment::STATUS_PROPOSED, Appointment::STATUS_ACCEPTED])
                ->last();

            // Concurrent retries of the exact same staff request are idempotent after the
            // application lock: do not consume a new attempt or send a second notification.
            if ($latestActive
                && $latestActive->branch_id === $branch->id
                && $latestActive->scheduled_date->toDateString() === $scheduledDate->toDateString()
                && $this->normalizeTime($latestActive->scheduled_time) === $normalizedTime
                && $latestActive->status === $desiredAppointmentStatus) {
                return $latestActive->setRelation('branch', $branch);
            }

            $activeForDay = Appointment::query()
                ->where('branch_id', $branch->id)
                ->whereDate('scheduled_date', $scheduledDate)
                ->whereNotIn('status', [Appointment::STATUS_REJECTED, Appointment::STATUS_CANCELLED])
                ->whereHas('creditApplication', fn ($query) => $query->where('status', '!=', CreditApplication::STATUS_CANCELLED))
                ->lockForUpdate()
                ->get();

            if ($activeForDay->contains(fn (Appointment $item) => $item->credit_application_id !== $lockedApplication->id
                && $this->normalizeTime($item->scheduled_time) === $normalizedTime)) {
                throw new ApiException(ApiErrorCode::NoSlotsAvailable, 'This appointment slot is already occupied.', 409);
            }

            $otherApplicationCount = $activeForDay->where('credit_application_id', '!=', $lockedApplication->id)->count();
            if ($otherApplicationCount >= max(1, $branch->daily_capacity)) {
                throw new ApiException(ApiErrorCode::NoSlotsAvailable, 'This branch has reached its daily appointment capacity.', 409);
            }

            $appointments
                ->whereIn('status', [Appointment::STATUS_PROPOSED, Appointment::STATUS_ACCEPTED])
                ->each->update(['status' => Appointment::STATUS_CANCELLED, 'decided_at' => now()]);

            $attemptNumber = ((int) $appointments->max('attempt_number')) + 1;
            $targetStatus = $directConfirm
                ? CreditApplication::STATUS_APPOINTMENT_CONFIRMED
                : CreditApplication::STATUS_APPOINTMENT_PROPOSED;

            $this->stateMachine->apply(
                $lockedApplication,
                $targetStatus,
                $actor,
                ['branch_id' => $branch->id],
                $ip,
                $userAgent,
            );

            $appointment = Appointment::create([
                'credit_application_id' => $lockedApplication->id,
                'branch_id' => $branch->id,
                'attempt_number' => $attemptNumber,
                'reschedule_count' => (int) $appointments->max('reschedule_count'),
                'scheduled_date' => $scheduledDate->toDateString(),
                'scheduled_time' => $normalizedTime,
                'status' => $desiredAppointmentStatus,
                'decided_at' => $directConfirm ? now() : null,
                'is_auto_scheduled_future' => false,
            ]);

            $this->auditLog->log(
                'credit_application.appointment_manually_scheduled',
                staffUser: $actor,
                newState: [
                    'appointment_id' => $appointment->id,
                    'attempt' => $attemptNumber,
                    'branch_id' => $branch->id,
                    'date' => $scheduledDate->toDateString(),
                    'time' => $normalizedTime,
                    'direct_confirm' => $directConfirm,
                ],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $lockedApplication,
            );

            return $appointment->setRelation('branch', $branch);
        }, 5);
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

        // `$from` is a candidate hint, never permission to use today. Clamp every automatic
        // lookup to the first working day after the current business date.
        $minimumDate = $this->minimumAutomaticDate();
        $requestedDate = $from
            ? Carbon::parse($from->toDateString(), config('app.timezone'))->startOfDay()
            : $minimumDate->copy();
        $date = $this->nextWorkingDayOnOrAfter(
            $requestedDate->lt($minimumDate) ? $minimumDate : $requestedDate,
        );

        $initialWorkingDay = $date->copy();

        // Bounded search: a branch that's somehow permanently full would otherwise loop forever.
        for ($i = 0; $i < 365; $i++) {
            if (! $this->isWorkingDay($date)) {
                $date = $this->nextWorkingDayOnOrAfter($date->copy()->addDay());

                continue;
            }

            $activeAppointments = Appointment::query()
                ->where('branch_id', $branch->id)
                ->whereDate('scheduled_date', $date)
                ->whereNotIn('status', [Appointment::STATUS_REJECTED, Appointment::STATUS_CANCELLED])
                ->whereHas('creditApplication', function ($q) {
                    $q->where('status', '!=', CreditApplication::STATUS_CANCELLED);
                })
                ->lockForUpdate()
                ->get(['scheduled_time']);

            if ($activeAppointments->count() >= max(1, $branch->daily_capacity)) {
                $date = $this->nextWorkingDayOnOrAfter($date->copy()->addDay());

                continue;
            }

            $occupiedSlots = $activeAppointments
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
            $date = $this->nextWorkingDayOnOrAfter($date->copy()->addDay());
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

    /** @return array{0: Appointment, 1: CreditApplication, 2: Branch, 3: int} */
    private function proposeNextLocked(CreditApplication $application, Branch $branch, User|StaffUser $actor, ?string $ip, ?string $userAgent): array
    {
        $previous = Appointment::query()
            ->where('credit_application_id', $application->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();
        $attemptNumber = $previous ? $previous->attempt_number + 1 : 1;
        $rescheduleCount = max(0, (int) ($previous?->reschedule_count ?? 0))
            + ($previous && $actor instanceof User ? 1 : 0);

        if ($rescheduleCount > Appointment::MAX_RESCHEDULES) {
            throw new ApiException(ApiErrorCode::AppointmentRescheduleLimit);
        }

        $slot = $this->findNextSlot($branch, application: $application);
        $appointment = Appointment::create([
            'credit_application_id' => $application->id,
            'branch_id' => $branch->id,
            'attempt_number' => $attemptNumber,
            'reschedule_count' => $rescheduleCount,
            'scheduled_date' => $slot['date'],
            'scheduled_time' => $slot['time'],
            'status' => Appointment::STATUS_PROPOSED,
            'is_auto_scheduled_future' => $slot['is_auto_scheduled_future'],
        ]);

        $this->stateMachine->apply($application, CreditApplication::STATUS_APPOINTMENT_PROPOSED, $actor, ip: $ip, userAgent: $userAgent);

        return [$appointment, $application, $branch, $attemptNumber];
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function businessToday(): Carbon
    {
        return Carbon::now(config('app.timezone'))->startOfDay();
    }

    private function minimumAutomaticDate(): Carbon
    {
        return $this->nextWorkingDayOnOrAfter($this->businessToday()->addDay());
    }

    private function nextWorkingDayOnOrAfter(Carbon $date): Carbon
    {
        $candidate = Carbon::parse($date->toDateString(), config('app.timezone'))->startOfDay();

        while (! $this->isWorkingDay($candidate)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function isWorkingDay(Carbon $date): bool
    {
        return ! $date->isWeekend();
    }
}
