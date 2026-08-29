<?php

namespace App\Enums;

/**
 * Every error code the API can return, with its default HTTP status and default client-facing
 * message. ApiException consumes this enum, so a code can never be misspelled, and code/status/
 * message stay in one place instead of being re-typed at each throw site. Codes keep the exact
 * string values the frontends and tests match on — only the construction was centralized.
 *
 * A site that needs a dynamic message or a different status (e.g. MAX_ATTEMPTS_EXCEEDED used by
 * both OTP and appointment proposals) passes its own message/status when constructing
 * ApiException; everything else picks up the defaults below.
 */
enum ApiErrorCode: string
{
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case RateLimited = 'RATE_LIMITED';
    case InvalidToken = 'INVALID_TOKEN';
    case OtpInvalid = 'OTP_INVALID';
    case OtpExpired = 'OTP_EXPIRED';
    case MaxAttemptsExceeded = 'MAX_ATTEMPTS_EXCEEDED';
    case OtpDispatchFailed = 'OTP_DISPATCH_FAILED';
    case InvalidResetToken = 'INVALID_RESET_TOKEN';
    case GoogleTokenInvalid = 'GOOGLE_TOKEN_INVALID';
    case Forbidden = 'FORBIDDEN';
    case ApplicationLocked = 'APPLICATION_LOCKED';
    case ApplicationCannotBeDeleted = 'APPLICATION_CANNOT_BE_DELETED';
    case ApplicationNotLocked = 'APPLICATION_NOT_LOCKED';
    case Validation1Required = 'VALIDATION_1_REQUIRED';
    case StepsIncomplete = 'STEPS_INCOMPLETE';
    case InvalidStatusFilter = 'INVALID_STATUS_FILTER';
    case NotUnderReview = 'NOT_UNDER_REVIEW';
    case InvalidApplicationStatus = 'INVALID_APPLICATION_STATUS';
    case NoBranchAvailable = 'NO_BRANCH_AVAILABLE';
    case NoSlotsAvailable = 'NO_SLOTS_AVAILABLE';
    case NotCurrentAppointment = 'NOT_CURRENT_APPOINTMENT';
    case AppointmentAlreadyDecided = 'APPOINTMENT_ALREADY_DECIDED';
    case AppointmentRescheduleLimit = 'APPOINTMENT_RESCHEDULE_LIMIT';
    case NoAppointment = 'NO_APPOINTMENT';
    case DocumentNotFound = 'DOCUMENT_NOT_FOUND';
    case DocumentMalwareDetected = 'DOCUMENT_MALWARE_DETECTED';
    case DocumentNotClean = 'DOCUMENT_NOT_CLEAN';
    case MalwareScannerUnavailable = 'MALWARE_SCANNER_UNAVAILABLE';
    case ReportNotOpen = 'REPORT_NOT_OPEN';
    case DuplicateEntry = 'DUPLICATE_ENTRY';
    case BankingAccountUnavailable = 'BANKING_ACCOUNT_UNAVAILABLE';
    case BankingInsufficientFunds = 'BANKING_INSUFFICIENT_FUNDS';
    case BankingCurrencyMismatch = 'BANKING_CURRENCY_MISMATCH';
    case BankingIdempotencyConflict = 'BANKING_IDEMPOTENCY_CONFLICT';
    case BankingTransferLimitExceeded = 'BANKING_TRANSFER_LIMIT_EXCEEDED';
    case BankingTransferRequestUnavailable = 'BANKING_TRANSFER_REQUEST_UNAVAILABLE';
    case BankingSelfApproval = 'BANKING_SELF_APPROVAL';
    case AnalyticsExportLimit = 'ANALYTICS_EXPORT_LIMIT';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials, self::InvalidToken, self::GoogleTokenInvalid => 401,
            self::OtpExpired => 410,
            self::OtpInvalid, self::InvalidResetToken => 400,
            self::RateLimited, self::MaxAttemptsExceeded => 429,
            self::OtpDispatchFailed, self::NoBranchAvailable, self::NoSlotsAvailable,
            self::MalwareScannerUnavailable => 503,
            self::Forbidden, self::AccountSuspended, self::ApplicationLocked,
            self::DocumentNotClean => 403,
            self::ApplicationCannotBeDeleted, self::ApplicationNotLocked, self::Validation1Required, self::StepsIncomplete,
            self::InvalidApplicationStatus, self::NotCurrentAppointment,
            self::AppointmentAlreadyDecided, self::AppointmentRescheduleLimit, self::BankingAccountUnavailable,
            self::BankingInsufficientFunds, self::BankingCurrencyMismatch,
            self::BankingIdempotencyConflict, self::BankingTransferLimitExceeded,
            self::BankingTransferRequestUnavailable, self::BankingSelfApproval => 409,
            self::InvalidStatusFilter, self::DuplicateEntry, self::DocumentMalwareDetected,
            self::AnalyticsExportLimit => 422,
            self::NotUnderReview, self::NoAppointment, self::DocumentNotFound,
            self::ReportNotOpen => 404,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::InvalidCredentials => 'The email/phone or password is incorrect.',
            self::AccountSuspended => 'This account is suspended.',
            self::RateLimited => 'Please wait before requesting another code.',
            self::InvalidToken => 'This session has expired. Please start again.',
            self::OtpInvalid => 'Invalid or expired verification code.',
            self::OtpExpired => 'This verification code has expired.',
            self::MaxAttemptsExceeded => 'Too many incorrect attempts. Request a new code.',
            self::OtpDispatchFailed => 'Could not send the verification code. Please try again.',
            self::InvalidResetToken => 'This reset link is invalid or has expired.',
            self::GoogleTokenInvalid => 'Google sign-in failed.',
            self::Forbidden => 'You do not have access to this resource.',
            self::ApplicationLocked => 'This application has been finalized and can no longer be modified.',
            self::ApplicationCannotBeDeleted => 'Only an unfinished application can be deleted. A validated application must be retained.',
            self::ApplicationNotLocked => 'The application must be finalized before it can be submitted.',
            self::Validation1Required => 'Validation 1 must pass before validation 2.',
            self::StepsIncomplete => 'Complete all three steps before running validation.',
            self::InvalidStatusFilter => 'Unknown status filter.',
            self::NotUnderReview => 'This application has not been submitted yet.',
            self::InvalidApplicationStatus => 'This application is not in the expected stage.',
            self::NoBranchAvailable => 'No branch is configured to handle this appointment yet.',
            self::NoSlotsAvailable => 'This branch has no available appointment slots.',
            self::NotCurrentAppointment => 'This is not the current appointment proposal.',
            self::AppointmentAlreadyDecided => 'This appointment has already been decided.',
            self::AppointmentRescheduleLimit => 'Vous avez utilisé vos 4 changements de rendez-vous. Contactez votre agence pour toute nouvelle modification.',
            self::NoAppointment => 'No appointment has been proposed for this application yet.',
            self::DocumentNotFound => 'Document not found.',
            self::DocumentMalwareDetected => 'The uploaded document was rejected by malware protection.',
            self::DocumentNotClean => 'This document is not cleared for download.',
            self::MalwareScannerUnavailable => 'Document malware scanning is temporarily unavailable.',
            self::ReportNotOpen => 'This application has no open report.',
            self::DuplicateEntry => 'A record with this value already exists.',
            self::BankingAccountUnavailable => 'This bank account is not available for the requested operation.',
            self::BankingInsufficientFunds => 'This account has insufficient available funds.',
            self::BankingCurrencyMismatch => 'Both bank accounts must use the same currency.',
            self::BankingIdempotencyConflict => 'This idempotency key was already used with different transaction data.',
            self::BankingTransferLimitExceeded => 'This transfer exceeds the configured banking limit.',
            self::BankingTransferRequestUnavailable => 'This transfer request is no longer available for review.',
            self::BankingSelfApproval => 'The staff member who requested a transfer cannot approve or reject it.',
            self::AnalyticsExportLimit => 'The requested aggregate export is too large.',
        };
    }
}
