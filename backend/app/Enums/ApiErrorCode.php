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
    case NoAppointment = 'NO_APPOINTMENT';
    case DocumentNotFound = 'DOCUMENT_NOT_FOUND';
    case ReportNotOpen = 'REPORT_NOT_OPEN';
    case DuplicateEntry = 'DUPLICATE_ENTRY';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials, self::InvalidToken, self::GoogleTokenInvalid => 401,
            self::OtpExpired => 410,
            self::OtpInvalid, self::InvalidResetToken => 400,
            self::RateLimited, self::MaxAttemptsExceeded => 429,
            self::OtpDispatchFailed, self::NoBranchAvailable, self::NoSlotsAvailable => 503,
            self::Forbidden, self::AccountSuspended, self::ApplicationLocked => 403,
            self::ApplicationNotLocked, self::Validation1Required, self::StepsIncomplete,
            self::InvalidApplicationStatus, self::NotCurrentAppointment,
            self::AppointmentAlreadyDecided => 409,
            self::InvalidStatusFilter, self::DuplicateEntry => 422,
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
            self::NoAppointment => 'No appointment has been proposed for this application yet.',
            self::DocumentNotFound => 'Document not found.',
            self::ReportNotOpen => 'This application has no open report.',
            self::DuplicateEntry => 'A record with this value already exists.',
        };
    }
}
