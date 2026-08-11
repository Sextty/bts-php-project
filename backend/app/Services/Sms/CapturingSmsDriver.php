<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;

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

    public function send(string $to, string $message): SmsDeliveryResult
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return SmsDeliveryResult::success();
    }

    public function lastCode(): string
    {
        $last = end($this->sent);
        preg_match('/\d{6}/', $last['message'], $matches);

        return $matches[0];
    }
}
