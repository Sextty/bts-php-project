<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
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
    ) {}

    public function proposeNext(CreditApplication $application, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $previous = $application->latestAppointment();
        $attemptNumber = $previous ? $previous->attempt_number + 1 : 1;

        if ($attemptNumber > Appointment::MAX_ATTEMPTS) {
            throw new ApiException('MAX_ATTEMPTS_EXCEEDED', 'No more appointment proposals are available.', status: 409);
        }

        // Same branch every attempt — the project's location doesn't change between them, so
        // re-matching would just do redundant work (and risk picking a different branch if the
        // matching data changed mid-cycle, which would be confusing for the customer).
        $branch = $previous ? $previous->branch : $this->branchMatching->findForApplication($application);

        $appointment = DB::transaction(function () use ($application, $branch, $attemptNumber) {
            [$date, $slotTime] = $this->findNextSlot($branch);

            return Appointment::create([
                'credit_application_id' => $application->id,
                'branch_id' => $branch->id,
                'attempt_number' => $attemptNumber,
                'scheduled_date' => $date,
                'scheduled_time' => $slotTime,
                'status' => Appointment::STATUS_PROPOSED,
            ]);
        });

        $application->update(['status' => CreditApplication::STATUS_APPOINTMENT_PROPOSED]);

        $this->auditLog->log(
            'credit_application.appointment_proposed',
            $application->user,
            newState: ['attempt' => $attemptNumber, 'branch_id' => $branch->id, 'date' => (string) $appointment->scheduled_date, 'time' => $appointment->scheduled_time],
            ipAddress: $ip,
            userAgent: $userAgent,
            application: $application,
        );

        return $appointment;
    }

    public function accept(CreditApplication $application, Appointment $appointment, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $this->assertCurrentAndProposed($application, $appointment);

        $appointment->update(['status' => Appointment::STATUS_ACCEPTED, 'decided_at' => now()]);
        $application->update(['status' => CreditApplication::STATUS_APPOINTMENT_CONFIRMED]);

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
    public function reject(CreditApplication $application, Appointment $appointment, ?string $ip = null, ?string $userAgent = null): Appointment
    {
        $this->assertCurrentAndProposed($application, $appointment);

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
            $application->update(['status' => CreditApplication::STATUS_APPOINTMENT_LOCKED]);

            $this->auditLog->log(
                'credit_application.appointment_locked',
                $application->user,
                newState: ['reason' => 'max_attempts_exceeded'],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $application,
            );

            return $appointment->fresh();
        }

        $this->proposeNext($application, $ip, $userAgent);

        return $appointment->fresh();
    }

    /** @return array{0: string, 1: string} [date (Y-m-d), slot time (H:i:s)] */
    private function findNextSlot(Branch $branch): array
    {
        $slotTimes = $branch->slotTimes();
        $date = Carbon::tomorrow();

        // Bounded search: a branch that's somehow permanently full would otherwise loop forever.
        for ($i = 0; $i < 365; $i++) {
            $takenCount = Appointment::query()
                ->where('branch_id', $branch->id)
                ->whereDate('scheduled_date', $date)
                ->where('status', '!=', Appointment::STATUS_REJECTED)
                ->lockForUpdate()
                ->count();

            if ($takenCount < count($slotTimes)) {
                return [$date->toDateString(), $slotTimes[$takenCount]];
            }

            $date = $date->copy()->addDay();
        }

        throw new ApiException('NO_SLOTS_AVAILABLE', 'This branch has no available appointment slots.', status: 503);
    }

    private function assertCurrentAndProposed(CreditApplication $application, Appointment $appointment): void
    {
        $latest = $application->latestAppointment();

        if (! $latest || $latest->id !== $appointment->id) {
            throw new ApiException('NOT_CURRENT_APPOINTMENT', 'This is not the current appointment proposal.', status: 409);
        }

        if ($appointment->status !== Appointment::STATUS_PROPOSED) {
            throw new ApiException('APPOINTMENT_ALREADY_DECIDED', 'This appointment has already been decided.', status: 409);
        }
    }
}
