<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Jobs\DeliverOtpEmailJob;
use App\Models\User;
use App\ValueObjects\OtpMessage;
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
 *
 * The logo is embedded as a real inline attachment (Content-ID), not a base64 data URI: the
 * latter is stripped by several clients (notably Outlook desktop), so the brand mark silently
 * disappears. A cid: reference + inline DataPart renders everywhere.
 *
 * Delivery is synchronous by default (services.otp.queue_delivery=false — no queue worker in
 * dev). With the flag on, the same email is dispatched as DeliverOtpEmailJob instead, which
 * renders the identical emails.otp view on the worker.
 */
class EmailOtpDriver implements SmsProviderInterface
{
    public function send(User $user, OtpMessage $message): SmsDeliveryResult
    {
        if (config('services.otp.queue_delivery', false)) {
            DeliverOtpEmailJob::dispatch($user, $message->code, $message->ttlMinutes);

            return SmsDeliveryResult::success();
        }

        try {
            $code = $message->code;
            $ttlMinutes = $message->ttlMinutes;

            Mail::html('', function ($mail) use ($user, $code, $ttlMinutes) {
                // embedData returns the full cid:... string and registers the inline part,
                // so the logo is an MIME attachment every client can display.
                $logoCid = $mail->embedData(
                    (string) file_get_contents(resource_path('images/logo.png')),
                    'bts-logo.png',
                    'image/png',
                );

                $html = view('emails.otp', [
                    'code' => $code,
                    'ttlMinutes' => $ttlMinutes,
                    'logoCid' => $logoCid,
                ])->render();

                $mail->to($user->email)
                    ->subject('Your BTS Bank verification code')
                    ->html($html);
            });

            return SmsDeliveryResult::success();
        } catch (\Throwable $e) {
            Log::error('[otp:email] send failed', [
                'user_id' => $user->id,
                'exception_class' => $e::class,
            ]);

            return SmsDeliveryResult::failure($e->getMessage(), transient: true);
        }
    }

    public function canReach(User $user): bool
    {
        return (bool) $user->email;
    }
}
