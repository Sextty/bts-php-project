<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/**
 * Owns the internal review stage of the state machine (SUBMITTED → STAFF_APPROVED/STAFF_REJECTED
 * → APPROVED/REJECTED) — kept separate from CreditApplicationService, which owns the customer-
 * facing steps. Different actor, different concern; bolting this onto the same class would mix
 * "what the customer can do to their own draft" with "what an internal reviewer can do to a
 * submitted application."
 */
class CreditApplicationReviewService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AppointmentSchedulingService $appointmentScheduling,
    ) {}

    public function staffApprove(CreditApplication $application, StaffUser $staff, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $this->assertStatus($application, CreditApplication::STATUS_SUBMITTED);

        $application->update([
            'status' => CreditApplication::STATUS_STAFF_APPROVED,
            'decided_by_staff_user_id' => $staff->id,
        ]);

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

        return $application->fresh();
    }

    public function staffReject(CreditApplication $application, StaffUser $staff, string $reason, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $this->assertStatus($application, CreditApplication::STATUS_SUBMITTED);

        $application->update([
            'status' => CreditApplication::STATUS_STAFF_REJECTED,
            'decided_by_staff_user_id' => $staff->id,
            'rejection_reason' => $reason,
        ]);

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

        return $application->fresh();
    }

    public function adminApprove(CreditApplication $application, StaffUser $admin, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $this->assertStatus($application, CreditApplication::STATUS_STAFF_APPROVED);

        // Wrapped in a transaction: proposeNext() can throw (e.g. NO_BRANCH_AVAILABLE) if no
        // branch is configured. Without this, the APPROVED write below would already be
        // committed by the time that happens, stranding the application at APPROVED with no
        // appointment and no way to retry — adminApprove() itself requires STAFF_APPROVED to
        // run again. Rolling the whole chain back on failure keeps the application at
        // STAFF_APPROVED, so admin-approve is safely retryable once a branch exists.
        return DB::transaction(function () use ($application, $admin, $ip, $userAgent) {
            $application->update([
                'status' => CreditApplication::STATUS_APPROVED,
                'decided_by_admin_user_id' => $admin->id,
            ]);

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
            $this->appointmentScheduling->proposeNext($application->fresh(), $ip, $userAgent);

            return $application->fresh();
        });
    }

    public function adminReject(CreditApplication $application, StaffUser $admin, string $reason, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $this->assertStatus($application, CreditApplication::STATUS_STAFF_APPROVED);

        $application->update([
            'status' => CreditApplication::STATUS_REJECTED,
            'decided_by_admin_user_id' => $admin->id,
            'rejection_reason' => $reason,
        ]);

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

        return $application->fresh();
    }

    private function assertStatus(CreditApplication $application, string $expected): void
    {
        if ($application->status !== $expected) {
            throw new ApiException(
                'INVALID_APPLICATION_STATUS',
                "This application is not in the {$expected} stage.",
                status: 409,
            );
        }
    }
}
