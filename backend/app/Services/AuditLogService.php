<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\User;

/**
 * Records security-relevant events (Part 9 of the auth plan). Never called with plaintext
 * passwords or OTP codes — only their existence/outcome (e.g. 'otp.failed', not the code that
 * failed). Takes ip/userAgent as explicit parameters rather than reaching into the global
 * `request()` helper, so this stays testable without a real HTTP request in scope.
 */
class AuditLogService
{
    public function log(
        string $action,
        ?User $user = null,
        array $previousState = [],
        array $newState = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?CreditApplication $application = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user?->id,
            'credit_application_id' => $application?->id,
            'action' => $action,
            'previous_state' => $previousState ?: null,
            'new_state' => $newState ?: null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
