<?php

namespace App\Enums;

/**
 * Every discrete capability the backend grants. Middleware gates routes on these
 * (permission:application.review), and roles are defined purely as sets of them in
 * PermissionRegistry — so a new role is a registry entry, not a new middleware or a copy of
 * an old one.
 *
 * The list is deliberately authoritative: 'such as' in the design brief, these are the
 * permissions that exist. document.verify and users.manage are granted to the roles that will
 * use them but gate no route yet — they exist so the registry and the middleware are the same
 * when those endpoints arrive.
 */
enum Permission: string
{
    case ApplicationView = 'application.view';
    case ApplicationCreate = 'application.create';
    case ApplicationUpdate = 'application.update';
    case ApplicationReview = 'application.review';
    case ApplicationReject = 'application.reject';
    case ApplicationApprove = 'application.approve';
    case ApplicationFinalApprove = 'application.final_approve';
    case DocumentView = 'document.view';
    case DocumentVerify = 'document.verify';
    case AppointmentManage = 'appointment.manage';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
}
