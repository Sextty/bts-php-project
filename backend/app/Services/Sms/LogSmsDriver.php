<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Dev-safe default: writes the message to the Laravel log instead of sending it anywhere. Used
 * whenever SMS_PROVIDER=log, which is the local default — read the code out of storage/logs.
 */
class LogSmsDriver implements SmsProviderInterface
{
    public function send(User $user, string $message): SmsDeliveryResult
    {
        Log::channel(config('logging.default'))->info('[sms:log-driver] outgoing SMS', [
            'to' => (string) $user->phone,
            'message' => $message,
        ]);

        return SmsDeliveryResult::success();
    }

    public function canReach(User $user): bool
    {
        // The log always accepts anything — that is the point of this driver.
        return true;
    }
}
