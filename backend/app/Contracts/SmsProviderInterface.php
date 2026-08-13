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
 * recipient the same way — SMS drivers use $user->phone, email uses $user->email — and only the
 * driver needs to know which. Keeping that resolution inside the driver is what lets OtpService
 * stay channel-agnostic.
 */
interface SmsProviderInterface
{
    public function send(User $user, string $message): SmsDeliveryResult;

    /**
     * Whether this channel can actually reach the user right now. Exists for channels that
     * require a one-time handshake before their first message (e.g. Telegram, no longer used
     * here) — callers check this before generating an OTP that would have nowhere to go. Email
     * and SMS drivers can always reach a user who has one on file, so this is unconditionally
     * true for them.
     */
    public function canReach(User $user): bool;
}
