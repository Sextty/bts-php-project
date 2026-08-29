<?php

namespace App\Enums;

/**
 * Every discrete capability the backend grants. Middleware gates routes on these
 * (permission:application.review), and roles are defined purely as sets of them in
 * PermissionRegistry — so a new role is a registry entry, not a new middleware or a copy of
 * an old one.
 */
enum Permission: string
{
    // Application permissions
    case ApplicationView = 'application.view';
    case ApplicationCreate = 'application.create';
    case ApplicationUpdate = 'application.update';
    case ApplicationReview = 'application.review';
    case ApplicationReject = 'application.reject';
    case ApplicationApprove = 'application.approve';
    case ApplicationFinalApprove = 'application.final_approve';

    // Document permissions
    case DocumentView = 'document.view';
    case DocumentVerify = 'document.verify';

    // Appointment & report permissions
    case AppointmentManage = 'appointment.manage';
    case ReportsView = 'reports.view';
    case AnalyticsView = 'analytics.view';
    case AnalyticsExport = 'analytics.export';

    // Audit & User management
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';

    // Core banking
    case BankingAccountsView = 'banking.accounts.view';
    case BankingAccountsManage = 'banking.accounts.manage';
    case BankingTransfersPropose = 'banking.transfers.propose';
    case BankingTransfersApprove = 'banking.transfers.approve';

    // Security Center (SC Team) permissions
    case SecurityDashboardView = 'security.dashboard.view';
    case SecurityAuditView = 'security.audit.view';
    case SecurityAuditExport = 'security.audit.export';
    case SecurityTelemetryView = 'security.telemetry.view';
    case SecurityOsqueryView = 'security.osquery.view';
    case SecurityOsqueryExecute = 'security.osquery.execute';
    case SecurityOsqueryExport = 'security.osquery.export';
    case SecurityUsersView = 'security.users.view';
    case SecurityUsersSuspend = 'security.users.suspend';
    case SecurityUsersUnsuspend = 'security.users.unsuspend';
    case SecurityUsersRevokeTokens = 'security.users.revoke_tokens';
    case SecurityDataView = 'security.data.view';
    case SecurityDocumentsView = 'security.documents.view';
    case SecurityApplicationsView = 'security.applications.view';
    case SecurityAppointmentsView = 'security.appointments.view';
    case SecurityVulnerabilityView = 'security.vulnerability.view';
    case SecurityVulnerabilityScan = 'security.vulnerability.scan';
}
