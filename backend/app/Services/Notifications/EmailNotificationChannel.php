<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Models\AppNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Plain-text email channel. Reuses the mail system already wired for OTP emails; no template
 * file is required, so adding a new notification type never needs a new blade view. Uses the
 * notification's title as subject and body as the message.
 */
class EmailNotificationChannel implements NotificationChannelInterface
{
    public function canReach(Model $notifiable): bool
    {
        return method_exists($notifiable, 'email') && $notifiable->email !== null && $notifiable->email !== '';
    }

    public function deliver(Model $notifiable, AppNotification $notification): void
    {
        if (! $this->canReach($notifiable)) {
            Log::debug('[notification] email skipped — recipient has no email address', [
                'notification_id' => $notification->id,
            ]);

            return;
        }

        try {
            Mail::to($notifiable->email)->send(new class($notification) extends Mailable
            {
                public function __construct(private readonly AppNotification $notification) {}

                public function build(): Mailable
                {
                    return $this->subject($this->notification->title)
                        ->html('<p>'.e($this->notification->body).'</p>');
                }
            });
        } catch (\Throwable $e) {
            Log::warning('[notification] email delivery failed', [
                'notification_id' => $notification->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}