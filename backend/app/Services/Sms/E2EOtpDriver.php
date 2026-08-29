<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use App\ValueObjects\OtpMessage;
use RuntimeException;

/** Ephemeral Playwright-only transport. Never available outside the isolated e2e environment. */
class E2EOtpDriver implements SmsProviderInterface
{
    public function send(User $user, OtpMessage $message): SmsDeliveryResult
    {
        $path = (string) config('services.sms.e2e_otp_file', '');
        if (! app()->environment('e2e') || $path === '') {
            throw new RuntimeException('E2E OTP transport is disabled outside an isolated e2e run.');
        }

        $line = json_encode([
            'phone' => (string) $user->phone,
            'code' => $message->code,
            'issued_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR).PHP_EOL;

        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the ephemeral E2E OTP channel.');
        }

        return SmsDeliveryResult::success();
    }

    public function canReach(User $user): bool
    {
        return app()->environment('e2e');
    }
}
