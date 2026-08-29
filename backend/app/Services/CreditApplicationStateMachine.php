<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single owner of every credit_application.status transition.
 *
 * Previously each service updated `status` directly and re-checked the expected current state
 * with its own ad-hoc guard (assertStatus(), inline status comparisons), so the transition
 * rules were scattered and could drift apart. Here they live in one place — the TRANSITIONS
 * table below — and every status write in the codebase goes through apply(), which:
 *
 *   - rejects transitions that don't exist in the table (including duplicate same-state calls,
 *     with one deliberate exception: APPOINTMENT_PROPOSED → APPOINTMENT_PROPOSED, which is the
 *     re-proposal cycle after a rejection and is tracked by attempt_number on the new row);
 *   - rejects every transition not explicitly present in the table: backward moves, skipped
 *     customer steps, cross-zone moves, actor violations and terminal-state exits;
 *   - enforces the actor per transition (customer / staff / admin) server-side, as a second
 *     layer beneath the route middleware and policies;
 *   - applies the change inside a transaction, so the status can never be half-written;
 *   - records a uniform credit_application.status_changed audit row (services additionally log
 *     their richer domain events, e.g. the rejection reason or appointment attempt).
 *
 * The customer-facing statuses are protected end to end: `status` is not fillable on the model
 * (a mass-assignment payload can't touch it), there is no route that writes it directly, and
 * every legitimate write is a transition decided here — the machine is the only writer.
 */
class CreditApplicationStateMachine
{
    /**
     * @var array<string, array<string, string>> current status => [allowed next status => required actor]
     *
     * Actor values: 'customer' (authenticated User), 'staff' (StaffUser with role 'staff'),
     * 'admin' (StaffUser with role 'admin'). A status missing from this map — or with an empty
     * map — is terminal.
     */
    private const TRANSITIONS = [
        CreditApplication::STATUS_DRAFT => [
            CreditApplication::STATUS_STEP_1_COMPLETED => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        CreditApplication::STATUS_STEP_1_COMPLETED => [
            CreditApplication::STATUS_STEP_2_COMPLETED => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        CreditApplication::STATUS_STEP_2_COMPLETED => [
            CreditApplication::STATUS_STEP_3_COMPLETED => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        CreditApplication::STATUS_STEP_3_COMPLETED => [
            CreditApplication::STATUS_READY_FOR_VALIDATION_1 => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        CreditApplication::STATUS_READY_FOR_VALIDATION_1 => [
            CreditApplication::STATUS_VALIDATION_1_COMPLETED => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        CreditApplication::STATUS_VALIDATION_1_COMPLETED => [
            CreditApplication::STATUS_VALIDATION_2 => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        CreditApplication::STATUS_VALIDATION_2 => [
            CreditApplication::STATUS_FINAL_LOCKED => 'customer',
            CreditApplication::STATUS_CANCELLED => 'customer',
        ],
        // The customer is committed from here on: submitting, staff/admin decisions and the
        // appointment cycle can no longer be undone, and cancellation is off the table.
        CreditApplication::STATUS_FINAL_LOCKED => [
            CreditApplication::STATUS_SUBMITTED => 'customer',
        ],
        CreditApplication::STATUS_SUBMITTED => [
            CreditApplication::STATUS_STAFF_APPROVED => 'staff',
            CreditApplication::STATUS_STAFF_REJECTED => 'staff',
            CreditApplication::STATUS_CANCELLED => 'staff',
        ],
        CreditApplication::STATUS_STAFF_APPROVED => [
            CreditApplication::STATUS_APPROVED => 'admin',
            CreditApplication::STATUS_REJECTED => 'admin',
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => 'customer|staff|admin',
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 'customer|staff|admin',
            CreditApplication::STATUS_APPOINTMENT_LOCKED => 'customer|staff|admin',
            CreditApplication::STATUS_CANCELLED => 'staff',
        ],
        CreditApplication::STATUS_APPROVED => [
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => 'customer|staff|admin',
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 'customer|staff|admin',
            CreditApplication::STATUS_APPOINTMENT_LOCKED => 'customer|staff|admin',
        ],
        CreditApplication::STATUS_APPOINTMENT_PROPOSED => [
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => 'customer|staff|admin',
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 'customer|staff|admin',
            CreditApplication::STATUS_APPOINTMENT_LOCKED => 'customer|staff|admin',
        ],
        CreditApplication::STATUS_APPOINTMENT_CONFIRMED => [
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 'staff|admin',
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => 'staff|admin',
        ],
        CreditApplication::STATUS_APPOINTMENT_LOCKED => [
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 'staff|admin',
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => 'staff|admin',
        ],
        // Terminal states without further transition
        CreditApplication::STATUS_STAFF_REJECTED => [],
        CreditApplication::STATUS_REJECTED => [],
        CreditApplication::STATUS_CANCELLED => [],
    ];

    public function __construct(private readonly AuditLogService $auditLog) {}

    /** Whether $newStatus is a legal next state for this application by this actor. */
    public function canTransition(CreditApplication $application, string $newStatus, User|StaffUser $actor): bool
    {
        try {
            $this->assertTransitionAllowed($application, $newStatus, $actor);

            return true;
        } catch (ApiException) {
            return false;
        }
    }

    /**
     * Validates and applies one transition. $attributes are extra columns written atomically
     * with the status change (e.g. submitted_at, rejection_reason, decided_by_*).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function apply(
        CreditApplication $application,
        string $newStatus,
        User|StaffUser $actor,
        array $attributes = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): CreditApplication {
        return DB::transaction(function () use ($application, $newStatus, $actor, $attributes, $ip, $userAgent) {
            $lockedApplication = CreditApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransitionAllowed($lockedApplication, $newStatus, $actor);
            $previous = $lockedApplication->status;
            $changes = array_merge(['status' => $newStatus], $attributes);
            $lockedApplication->forceFill($changes)->save();

            // Mandatory evidence is part of the same transaction as the business write.
            // If chaining/audit persistence fails, the status and all supplied attributes roll back.
            $this->auditLog->log(
                'credit_application.status_changed',
                $actor instanceof StaffUser ? null : $actor,
                previousState: ['status' => $previous],
                newState: ['status' => $newStatus],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $lockedApplication,
                staffUser: $actor instanceof StaffUser ? $actor : null,
            );

            $application->setRawAttributes($lockedApplication->getAttributes(), true);

            return $lockedApplication;
        }, 5);
    }

    private function assertTransitionAllowed(CreditApplication $application, string $newStatus, User|StaffUser $actor): void
    {
        $from = $application->status;

        if (! array_key_exists($from, self::TRANSITIONS)) {
            throw new ApiException(
                ApiErrorCode::InvalidApplicationStatus,
                "This application cannot move from the {$from} stage.",
            );
        }

        $allowed = self::TRANSITIONS[$from];

        if ($from === $newStatus) {
            if (! array_key_exists($newStatus, $allowed)) {
                throw new ApiException(
                    ApiErrorCode::InvalidApplicationStatus,
                    "This application is already in the {$newStatus} stage.",
                );
            }
        } elseif (! array_key_exists($newStatus, $allowed)) {
            throw new ApiException(
                ApiErrorCode::InvalidApplicationStatus,
                "This application cannot move from the {$from} stage to {$newStatus}.",
            );
        }

        $required = $allowed[$newStatus];
        $allowedRoles = explode('|', $required);

        if ($actor instanceof StaffUser) {
            $isAuthorized = false;
            foreach ($allowedRoles as $role) {
                if ($role === 'staff' && $this->isOperationalStaff($actor)) {
                    $isAuthorized = true;
                    break;
                }

                if ($role === 'admin' && $actor->isAtLeast('admin')) {
                    $isAuthorized = true;
                    break;
                }
            }

            if (! $isAuthorized) {
                throw new ApiException(
                    ApiErrorCode::Forbidden,
                    "This action requires the {$required} role.",
                );
            }
        } else {
            if (! in_array('customer', $allowedRoles, true)) {
                throw new ApiException(
                    ApiErrorCode::Forbidden,
                    "This action requires the {$required} role.",
                );
            }
        }
    }

    private function isOperationalStaff(StaffUser $actor): bool
    {
        return $actor->isSuperuser() || in_array($actor->role, [
            'staff',
            'credit_officer',
            'senior_staff',
            'branch_manager',
        ], true);
    }
}
