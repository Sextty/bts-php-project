<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers OTP codes over Telegram instead of SMS.
 *
 * Chosen after carrier SMS proved undeliverable to Tunisian networks on every provider tried:
 * Twilio blocks trial accounts for Tunisia outright, and Vonage accepted (and billed for) three
 * test messages that Orange Tunisie left at status "unknown" and Ooredoo explicitly "rejected" —
 * unregistered alphanumeric sender IDs are filtered at the carrier, which needs regulator/carrier
 * registration to fix, not a code change. Telegram rides the public internet, so none of that
 * applies.
 *
 * Addresses a chat_id, not a phone number: Telegram bots can only message users who have started
 * a conversation with them first (an anti-spam rule, not a limitation we can work around). The
 * `telegram:link` Artisan command captures that chat_id after the user sends /start.
 */
class TelegramOtpDriver implements SmsProviderInterface
{
    public function __construct(private readonly string $botToken) {}

    public function send(User $user, string $message): SmsDeliveryResult
    {
        if (! $user->telegram_chat_id) {
            return SmsDeliveryResult::failure('This account is not linked to Telegram yet.');
        }

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $message,
                ]);

            if ($response->successful() && $response->json('ok') === true) {
                return SmsDeliveryResult::success();
            }

            // Telegram reports failures in the body with a description, not just an HTTP status.
            $description = $response->json('description') ?? "HTTP {$response->status()}";
            Log::warning('[otp:telegram] delivery rejected', [
                'chat_id' => $user->telegram_chat_id,
                'error' => $description,
            ]);

            return SmsDeliveryResult::failure($description);
        } catch (\Throwable $e) {
            Log::error('[otp:telegram] send failed', [
                'chat_id' => $user->telegram_chat_id,
                'exception' => $e->getMessage(),
            ]);

            return SmsDeliveryResult::failure($e->getMessage(), transient: true);
        }
    }

    public function canReach(User $user): bool
    {
        return (bool) $user->telegram_chat_id;
    }
}
