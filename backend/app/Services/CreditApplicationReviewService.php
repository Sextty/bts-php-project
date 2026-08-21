<?php

namespace App\Services;

use App\Models\CreditApplication;
use App\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/**
 * Owns the internal review stage of the state machine (SUBMITTED → STAFF_APPROVED/STAFF_REJECTED
 * → APPROVED/REJECTED) — kept separate from CreditApplicationService, which owns the customer-
 * facing steps. Different actor, different concern; bolting this onto the same class would mix
 * "what the customer can do to their own draft" with "what an internal reviewer can do to a
 * submitted application."
 *
 * Every decision is a transition through CreditApplicationStateMachine — the pre-checks that
 * used to live in assertStatus() are now the machine's transition table — and each one also
 * writes its domain audit event (with the rejection reason where applicable).
 */
class CreditApplicationReviewService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AppointmentSchedulingService $appointmentScheduling,
        private readonly BranchMatchingService $branchMatching,
        private readonly CreditApplicationStateMachine $stateMachine,
        private readonly NotificationService $notifications,
    ) {}

    public function staffApprove(CreditApplication $application, StaffUser $staff, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        return DB::transaction(function () use ($application, $staff, $ip, $userAgent) {
            $this->stateMachine->apply(
                $application,
                CreditApplication::STATUS_STAFF_APPROVED,
                $staff,
                attributes: ['decided_by_staff_user_id' => $staff->id],
                ip: $ip,
                userAgent: $userAgent,
            );

            $this->auditLog->log(
                'credit_application.staff_approved',
                $application->user,
                previousState: ['status' => CreditApplication::STATUS_SUBMITTED],
                newState: ['status' => CreditApplication::STATUS_STAFF_APPROVED],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $application,
                staffUser: $staff,
            );

            // Determine closest branch based on the project's location and persist if needed
            try {
                if (! $application->branch_id) {
                    $branch = $this->branchMatching->findForApplication($application);
                    $application->update(['branch_id' => $branch->id]);
                }
            } catch (\Throwable) {
                // Best effort if branch matching is not configured
            }

            $this->notifications->notifyUser(
                $application->user,
                'staff.approved',
                'Application approved at first review',
                sprintf(
                    'Your application %s has received initial review approval and is waiting for final management decision.',
                    $application->application_number,
                ),
                [
                    'application_id' => $application->id,
                    'application_number' => $application->application_number,
                    'branch_id' => $application->branch_id,
                ],
                dedupeKey: 'application-staff-approved-'.$application->id,
            );

            return $application->fresh();
        });
    }

    public function staffReject(CreditApplication $application, StaffUser $staff, string $reason, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $this->stateMachine->apply(
            $application,
            CreditApplication::STATUS_STAFF_REJECTED,
            $staff,
            attributes: ['decided_by_staff_user_id' => $staff->id, 'rejection_reason' => $reason],
            ip: $ip,
            userAgent: $userAgent,
        );

        $this->auditLog->log(
            'credit_application.staff_rejected',
            $application->user,
            previousState: ['status' => CreditApplication::STATUS_SUBMITTED],
            newState: ['status' => CreditApplication::STATUS_STAFF_REJECTED, 'reason' => $reason],
            ipAddress: $ip,
            userAgent: $userAgent,
            application: $application,
            staffUser: $staff,
        );

        $this->notifications->notifyUser(
            $application->user,
            'staff.rejected',
            'Application not approved at first review',
            'Your application '.$application->application_number.' was not approved: '.$reason,
            ['application_id' => $application->id, 'application_number' => $application->application_number],
            dedupeKey: 'application-'.$application->id,
        );

        return $application->fresh();
    }

    public function staffCancel(CreditApplication $application, StaffUser $staff, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        return DB::transaction(function () use ($application, $staff, $ip, $userAgent) {
            $previous = $application->status;

            $this->stateMachine->apply(
                $application,
                CreditApplication::STATUS_CANCELLED,
                $staff,
                ip: $ip,
                userAgent: $userAgent,
            );

            // Mark any existing appointments as cancelled so their slots become available immediately.
            $application->appointments()
                ->whereNotIn('status', [\App\Models\Appointment::STATUS_REJECTED, \App\Models\Appointment::STATUS_CANCELLED])
                ->update([
                    'status' => \App\Models\Appointment::STATUS_CANCELLED,
                    'decided_at' => now(),
                ]);

            $this->auditLog->log(
                'credit_application.cancelled',
                $application->user,
                previousState: ['status' => $previous],
                newState: ['status' => CreditApplication::STATUS_CANCELLED],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $application,
                staffUser: $staff,
            );

            $this->notifications->notifyUser(
                $application->user,
                'application.cancelled',
                'Application cancelled',
                'Your application '.$application->application_number.' has been cancelled by BTS Bank staff. You can open a discussion if you have questions.',
                ['application_id' => $application->id, 'application_number' => $application->application_number],
                dedupeKey: 'application-'.$application->id,
            );

            return $application->fresh();
        });
    }

    public function adminApprove(CreditApplication $application, StaffUser $admin, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        // Wrapped in a transaction: proposeNext() can throw (e.g. NO_BRANCH_AVAILABLE) if no
        // branch is configured. Without this, the APPROVED write below would already be
        // committed by the time that happens, stranding the application at APPROVED with no
        // appointment and no way to retry — adminApprove() itself requires STAFF_APPROVED to
        // run again. Rolling the whole chain back on failure keeps the application at
        // STAFF_APPROVED, so admin-approve is safely retryable once a branch exists.
        return DB::transaction(function () use ($application, $admin, $ip, $userAgent) {
            $this->stateMachine->apply(
                $application,
                CreditApplication::STATUS_APPROVED,
                $admin,
                attributes: ['decided_by_admin_user_id' => $admin->id],
                ip: $ip,
                userAgent: $userAgent,
            );

            $this->auditLog->log(
                'credit_application.admin_approved',
                $application->user,
                previousState: ['status' => CreditApplication::STATUS_STAFF_APPROVED],
                newState: ['status' => CreditApplication::STATUS_APPROVED],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $application,
                staffUser: $admin,
            );

            // Chained transition, same pattern as confirmValidationTwoAndLock(): approval
            // immediately triggers the first appointment proposal rather than leaving APPROVED
            // as a dead end the customer has no way to act on.
            $this->appointmentScheduling->proposeNext($application->fresh(), $admin, $ip, $userAgent);

            $this->notifications->notifyUser(
                $application->user,
                'admin.approved',
                'Application approved',
                'Your application '.$application->application_number.' has been approved. An appointment will be proposed to you.',
                ['application_id' => $application->id, 'application_number' => $application->application_number],
                dedupeKey: 'application-'.$application->id,
            );

            return $application->fresh();
        });
    }

    public function adminReject(CreditApplication $application, StaffUser $admin, string $reason, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $this->stateMachine->apply(
            $application,
            CreditApplication::STATUS_REJECTED,
            $admin,
            attributes: ['decided_by_admin_user_id' => $admin->id, 'rejection_reason' => $reason],
            ip: $ip,
            userAgent: $userAgent,
        );

        $this->auditLog->log(
            'credit_application.admin_rejected',
            $application->user,
            previousState: ['status' => CreditApplication::STATUS_STAFF_APPROVED],
            newState: ['status' => CreditApplication::STATUS_REJECTED, 'reason' => $reason],
            ipAddress: $ip,
            userAgent: $userAgent,
            application: $application,
            staffUser: $admin,
        );

        $this->notifications->notifyUser(
            $application->user,
            'admin.rejected',
            'Application rejected',
            'Your application '.$application->application_number.' was not approved: '.$reason,
            ['application_id' => $application->id, 'application_number' => $application->application_number],
            dedupeKey: 'application-'.$application->id,
        );

        return $application->fresh();
    }
}
