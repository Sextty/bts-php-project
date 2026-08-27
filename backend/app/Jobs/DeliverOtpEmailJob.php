<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued delivery of the OTP email — the async twin of EmailOtpDriver::send().
 *
 * The queue is opt-in: config('services.otp.queue_delivery', false) keeps the current
 * synchronous behaviour by default, because no queue worker runs in development. In
 * production, setting the flag to true moves every OTP email onto the queue
 * (QUEUE_CONNECTION=database, `php artisan queue:work`), turning the SMTP round-trip
 * latency into a background job.
 *
 * The plaintext code travels in the job payload exactly like it travels in OtpMessage
 * today (the DB only ever stores the hash — nothing changes about that invariant; the
 * queue payload is the same internal channel the SMS provider interface already uses).
 * The job renders the same emails.otp view with the same inline-logo embedding as the
 * sync driver, so queued vs. synchronous delivery is indistinguishable to the customer.
 */
class DeliverOtpEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  User  $user  the recipient — serialized by value, re-fetched on the worker
     */
    public function __construct(
        public readonly User $user,
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function handle(): void
    {
        Mail::html('', function ($mail) {
            // Same inline-logo embedding as EmailOtpDriver::send() — a cid: reference +
            // real MIME attachment, never a data URI that clients like Outlook strip.
            $logoCid = $mail->embedData(
                (string) file_get_contents(resource_path('images/logo.png')),
                'bts-logo.png',
                'image/png',
            );

            $html = view('emails.otp', [
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
                'logoCid' => $logoCid,
            ])->render();

            $mail->to($this->user->email)
                ->subject('Your BTS Bank verification code')
                ->html($html);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[otp:email:queue] delivery job failed', [
            'user_id' => $this->user->id,
            'exception_class' => $e::class,
        ]);
    }
}
