<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use App\ValueObjects\OtpMessage;
use Illuminate\Support\Facades\Log;

/**
 * Dev-safe transport simulator. It records delivery metadata only; OTP values and recipient
 * identifiers are never written to logs.
 */
class LogSmsDriver implements SmsProviderInterface
{
    public function send(User $user, OtpMessage $message): SmsDeliveryResult
    {
        Log::channel(config('logging.default'))->info('[sms:log-driver] SMS simulated', [
            'user_id' => $user->id,
        ]);

        return SmsDeliveryResult::success();
    }

    public function canReach(User $user): bool
    {
        // The log always accepts anything — that is the point of this driver.
        return true;
    }
}
