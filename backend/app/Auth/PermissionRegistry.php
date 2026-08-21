<?php

namespace App\Auth;

use App\Enums\Permission;
use App\Enums\Role;

/**
 * The single map from role to permissions — the whole authorization system reduces to this
 * table plus the ownership rules in the policies.
 *
 * Roles are looked up by string (not enum), so an unknown or not-yet-activated role degrades to
 * zero permissions instead of an exception — deny by default. Admin and super_admin are
 * superusers (every permission, now and after the enum grows): capabilities are granted by the
 * map, never re-checked per site.
 *
 * Adding a future role (credit_officer, senior_staff, branch_manager, super_admin) is one entry
 * here plus the DB enum widening — no middleware, policy or controller change.
 */
final class PermissionRegistry
{
    /** Every permission in the system, for the superuser roles. */
    private const ALL = [
        Permission::ApplicationView,
        Permission::ApplicationCreate,
        Permission::ApplicationUpdate,
        Permission::ApplicationReview,
        Permission::ApplicationReject,
        Permission::ApplicationApprove,
        Permission::ApplicationFinalApprove,
        Permission::DocumentView,
        Permission::DocumentVerify,
        Permission::AppointmentManage,
        Permission::ReportsView,
        Permission::AuditView,
        Permission::UsersManage,
    ];

    /** What a credit officer may do — identical to a plain staff member today. */
    private const CREDIT_OFFICER = [
        Permission::ApplicationView,
        Permission::ApplicationReview,
        Permission::ApplicationApprove,
        Permission::ApplicationReject,
        Permission::DocumentView,
        Permission::ReportsView,
        Permission::AuditView,
    ];

    /**
     * @var array<string, list<Permission>>
     */
    private const ROLE_PERMISSIONS = [
        Role::Client->value => [
            // The client's grants are scoped to their own records — see CreditApplicationPolicy,
            // which is the ownership half of every one of these.
            Permission::ApplicationView,
            Permission::ApplicationCreate,
            Permission::ApplicationUpdate,
            Permission::DocumentView,
            Permission::AppointmentManage,
            Permission::ReportsView,
        ],
        Role::Staff->value => self::CREDIT_OFFICER,
        Role::Admin->value => self::ALL,
        // Future roles — mapped now so the design is testable, spawnable later.
        Role::CreditOfficer->value => self::CREDIT_OFFICER,
        Role::SeniorStaff->value => [
            ...self::CREDIT_OFFICER,
            Permission::DocumentVerify,
        ],
        Role::BranchManager->value => [
            ...self::CREDIT_OFFICER,
            Permission::DocumentVerify,
            Permission::AppointmentManage,
        ],
        Role::SuperAdmin->value => self::ALL,
    ];

    /** @return list<Permission> */
    public static function permissionsFor(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    public static function has(string $role, Permission $permission): bool
    {
        return in_array($permission, self::permissionsFor($role), true);
    }
}
