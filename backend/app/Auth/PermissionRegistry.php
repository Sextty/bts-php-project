<?php

namespace App\Auth;

use App\Enums\Permission;
use App\Enums\Role;

/**
 * The single map from role to permissions — the whole authorization system reduces to this
 * table plus the ownership rules in the policies.
 *
 * Roles are looked up by string (not enum), so an unknown or not-yet-activated role degrades to
 * zero permissions instead of an exception — deny by default.
 */
final class PermissionRegistry
{
    /** Every permission in the system, for the superuser roles (Admin, SuperAdmin). */
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
        Permission::AnalyticsView,
        Permission::AnalyticsExport,
        Permission::AuditView,
        Permission::UsersManage,
        Permission::BankingAccountsView,
        Permission::BankingAccountsManage,
        Permission::BankingTransfersPropose,
        Permission::BankingTransfersApprove,
        Permission::SecurityDashboardView,
        Permission::SecurityAuditView,
        Permission::SecurityAuditExport,
        Permission::SecurityTelemetryView,
        Permission::SecurityOsqueryView,
        Permission::SecurityOsqueryExecute,
        Permission::SecurityOsqueryExport,
        Permission::SecurityUsersView,
        Permission::SecurityUsersSuspend,
        Permission::SecurityUsersUnsuspend,
        Permission::SecurityUsersRevokeTokens,
        Permission::SecurityDataView,
        Permission::SecurityDocumentsView,
        Permission::SecurityApplicationsView,
        Permission::SecurityAppointmentsView,
        Permission::SecurityVulnerabilityView,
        Permission::SecurityVulnerabilityScan,
    ];

    /**
     * What a credit officer / staff member may do — strictly operational credit review.
     * Audit and Osquery permissions are deliberately NOT granted to standard staff.
     */
    private const CREDIT_OFFICER = [
        Permission::ApplicationView,
        Permission::ApplicationReview,
        Permission::ApplicationApprove,
        Permission::ApplicationReject,
        Permission::DocumentView,
        Permission::ReportsView,
        Permission::AnalyticsView,
        Permission::AnalyticsExport,
    ];

    /**
     * What a Security Center (SC Team) operator may do:
     * Full audit, osquery, telemetry, user suspension/token revocation, vulnerability scanning,
     * and read-only compliance data inspection.
     * Note: Security is NOT SuperAdmin: they cannot create/approve/reject credit applications.
     */
    private const SECURITY_OFFICER = [
        Permission::AuditView,
        Permission::ApplicationView,
        Permission::DocumentView,
        Permission::ReportsView,
        Permission::UsersManage,
        Permission::SecurityDashboardView,
        Permission::SecurityAuditView,
        Permission::SecurityAuditExport,
        Permission::SecurityTelemetryView,
        Permission::SecurityOsqueryView,
        Permission::SecurityOsqueryExecute,
        Permission::SecurityOsqueryExport,
        Permission::SecurityUsersView,
        Permission::SecurityUsersSuspend,
        Permission::SecurityUsersUnsuspend,
        Permission::SecurityUsersRevokeTokens,
        Permission::SecurityDataView,
        Permission::SecurityDocumentsView,
        Permission::SecurityApplicationsView,
        Permission::SecurityAppointmentsView,
        Permission::SecurityVulnerabilityView,
        Permission::SecurityVulnerabilityScan,
    ];

    /**
     * @var array<string, list<Permission>>
     */
    private const ROLE_PERMISSIONS = [
        Role::Client->value => [
            Permission::ApplicationView,
            Permission::ApplicationCreate,
            Permission::ApplicationUpdate,
            Permission::DocumentView,
            Permission::AppointmentManage,
            Permission::ReportsView,
            Permission::BankingAccountsView,
        ],
        Role::Staff->value => self::CREDIT_OFFICER,
        Role::Security->value => self::SECURITY_OFFICER,
        Role::Admin->value => self::ALL,

        // Other internal roles
        Role::CreditOfficer->value => self::CREDIT_OFFICER,
        Role::SeniorStaff->value => [
            ...self::CREDIT_OFFICER,
            Permission::DocumentVerify,
            Permission::BankingTransfersPropose,
        ],
        Role::BranchManager->value => [
            ...self::CREDIT_OFFICER,
            Permission::DocumentVerify,
            Permission::AppointmentManage,
            Permission::BankingTransfersPropose,
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
