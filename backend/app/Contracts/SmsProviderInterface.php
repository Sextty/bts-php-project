<?php

namespace App\Contracts;

use App\Models\User;
use App\Services\Sms\SmsDeliveryResult;

/**
 * Swappable OTP transport. Bound in AppServiceProvider based on the SMS_PROVIDER env var, so a
 * different channel can be plugged in by adding one class that implements this interface and one
 * line in the binding switch — nothing else in the codebase (OtpService, controllers) references
 * a concrete provider.
 *
 * Takes the User rather than a bare destination string because not every channel addresses the
 * recipient the same way: SMS drivers use $user->phone, but Telegram addresses a chat_id, which
 * only the driver knows how to resolve. Keeping that resolution inside the driver is what lets
 * OtpService stay channel-agnostic.
 */
interface SmsProviderInterface
{
    public function send(User $user, string $message): SmsDeliveryResult;

    /**
     * Whether this channel can actually reach the user right now. Telegram cannot message an
     * account that has never opened a conversation with the bot, so callers check this before
     * generating an OTP that would have nowhere to go — the alternative is burning a code and
     * failing the request afterwards.
     */
    public function canReach(User $user): bool;
}
