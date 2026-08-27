<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Queued delivery of the password-reset email — the async twin of
 * User::sendPasswordResetNotification(). Mirrors it byte for byte: the same reset URL
 * pointed at the Next.js frontend (/reset-password), the same subject and body.
 *
 * The queue is opt-in: config('services.password_reset.queue_delivery', false) keeps
 * the current synchronous behaviour by default, because no queue worker runs in
 * development. In production, setting the flag to true moves the SMTP round-trip off
 * the request that triggers the reset-link send.
 */
class SendPasswordResetEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  User  $user  the recipient — serialized by value, re-fetched on the worker
     */
    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}

    public function handle(): void
    {
        $url = rtrim(config('app.frontend_url'), '/')."/reset-password?token={$this->token}&email=".urlencode($this->user->email);

        $this->user->notify(new class($url) extends Notification
        {
            public function __construct(private readonly string $url) {}

            public function via($notifiable): array
            {
                return ['mail'];
            }

            public function toMail($notifiable): MailMessage
            {
                return (new MailMessage)
                    ->subject('Reset your BTS Bank password')
                    ->line('You requested a password reset.')
                    ->action('Reset Password', $this->url)
                    ->line('This link expires shortly. If you did not request this, no action is needed.');
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[password-reset:queue] email job failed', [
            'user_id' => $this->user->id,
            'exception_class' => $e::class,
        ]);
    }
}
