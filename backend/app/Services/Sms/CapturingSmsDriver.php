<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;

/**
 * Test-only double: records every message instead of sending or logging it, so feature tests can
 * pull the plaintext OTP straight from the captured text — otp_codes only ever stores the hash,
 * same reasoning that made the platform being replaced's tests read OTPs from the delivery
 * record rather than the database. Bound over SmsProviderInterface per-test via
 * $this->app->instance(), never in production config.
 */
class CapturingSmsDriver implements SmsProviderInterface
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function send(User $user, string $message): SmsDeliveryResult
    {
        // Explicit cast: a freshly-created User can still hold the Stringable that
        // $request->string() produced, which the old string-typed signature coerced for us.
        $this->sent[] = ['to' => (string) $user->phone, 'message' => $message];

        return SmsDeliveryResult::success();
    }

    public function canReach(User $user): bool
    {
        return true;
    }

    public function lastCode(): string
    {
        $last = end($this->sent);
        preg_match('/\d{6}/', $last['message'], $matches);

        return $matches[0];
    }
}
