<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Dev-safe default: writes the message to the Laravel log instead of sending a real SMS. This is
 * the only driver implemented so far (SMS_PROVIDER=log) — no real carrier integration exists yet,
 * matching how the platform being replaced also shipped with mock-only providers and no real SMS
 * vendor wired up.
 */
class LogSmsDriver implements SmsProviderInterface
{
    public function send(string $to, string $message): SmsDeliveryResult
    {
        Log::channel(config('logging.default'))->info('[sms:log-driver] outgoing SMS', [
            'to' => $to,
            'message' => $message,
        ]);

        return SmsDeliveryResult::success();
    }
}
