<?php

namespace App\Enums;

/**
 * Every role the backend understands. The client role is the customer-facing User model; the
 * rest live on the staff_users.role column.
 *
 * Security role represents the Security Center (SC Team) operator with dedicated security,
 * audit, and compliance capabilities.
 */
enum Role: string
{
    case Client = 'client';
    case Staff = 'staff';
    case Admin = 'admin';
    case Security = 'security';

    // Future internal roles — designed for, not yet spawnable.
    case CreditOfficer = 'credit_officer';
    case SeniorStaff = 'senior_staff';
    case BranchManager = 'branch_manager';
    case SuperAdmin = 'super_admin';
}
