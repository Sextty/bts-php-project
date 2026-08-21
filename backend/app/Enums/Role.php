<?php

namespace App\Enums;

/**
 * Every role the backend understands. The client role is the customer-facing User model; the
 * rest live on the staff_users.role column.
 *
 * The four trailing roles are the ones the permission system was designed for — they are
 * recognized by PermissionRegistry and the role gate (isAtLeast) today, so activating them only
 * ever means widening the staff_users.role enum column in a migration, never touching a
 * middleware or controller. Nothing creates them before that migration exists.
 */
enum Role: string
{
    case Client = 'client';
    case Staff = 'staff';
    case Admin = 'admin';

    // Future internal roles — designed for, not yet spawnable.
    case CreditOfficer = 'credit_officer';
    case SeniorStaff = 'senior_staff';
    case BranchManager = 'branch_manager';
    case SuperAdmin = 'super_admin';
}
