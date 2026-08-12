<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Vonage\Client;
use Vonage\Client\Credentials\Basic;
use Vonage\Messages\Channel\SMS\SMSText;

/**
 * Real SMS delivery via Vonage (formerly Nexmo). Chosen over Twilio because Twilio blocks
 * trial accounts entirely for some countries (confirmed live for Tunisia — "Trials are
 * currently unavailable in Tunisia").
 *
 * Uses the Messages API (/v1/messages), not the older SMS API (/sms/json) — the Vonage
 * dashboard's "Messaging API type" setting must match this, or sends silently fail even
 * though the legacy endpoint still accepts and bills for them (caught live: 3 test messages
 * via the legacy SMS API were accepted/billed but never delivered, with delivery status
 * stuck on "unknown" or explicitly "rejected").
 */
class VonageSmsDriver implements SmsProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $brandName,
    ) {}

    public function send(User $user, string $message): SmsDeliveryResult
    {
        $client = new Client(new Basic($this->apiKey, $this->apiSecret));

        // Vonage expects a number with no leading '+' (e.g. 21620009977, not +21620009977).
        $recipient = ltrim((string) $user->phone, '+');

        try {
            $response = $client->messages()->send(new SMSText($recipient, $this->brandName, $message));

            Log::info('[sms:vonage] message accepted', ['to' => $recipient, 'response' => $response]);

            return SmsDeliveryResult::success();
        } catch (\Throwable $e) {
            Log::error('[sms:vonage] send failed', ['to' => $recipient, 'exception' => $e->getMessage()]);

            return SmsDeliveryResult::failure($e->getMessage(), transient: true);
        }
    }

    public function canReach(User $user): bool
    {
        return (bool) $user->phone;
    }
}
