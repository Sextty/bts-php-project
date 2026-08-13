<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers OTP codes by email instead of SMS/Telegram.
 *
 * Chosen after carrier SMS proved undeliverable to Tunisian networks on every provider tried
 * (Twilio blocks trials outright, Vonage's alphanumeric sender ID was carrier-filtered), and
 * Telegram — the interim fix — required every user to complete a one-time bot handshake before
 * their first code. Email needs no such handshake: every account already has a verified-format
 * address from registration, so canReach() is unconditionally true.
 */
class EmailOtpDriver implements SmsProviderInterface
{
    public function send(User $user, string $message): SmsDeliveryResult
    {
        try {
            Mail::raw($message, function ($mail) use ($user) {
                $mail->to($user->email)->subject('Your BTS Bank verification code');
            });

            return SmsDeliveryResult::success();
        } catch (\Throwable $e) {
            Log::error('[otp:email] send failed', [
                'email' => $user->email,
                'exception' => $e->getMessage(),
            ]);

            return SmsDeliveryResult::failure($e->getMessage(), transient: true);
        }
    }

    public function canReach(User $user): bool
    {
        return (bool) $user->email;
    }
}
