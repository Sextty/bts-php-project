<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Exceptions\Notifications\PermanentNotificationDeliveryException;
use App\Models\AppNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
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
        $email = $notifiable->getAttribute('email');

        return is_string($email) && $email !== '';
    }

    public function deliver(Model $notifiable, AppNotification $notification): void
    {
        if (! $this->canReach($notifiable)) {
            throw new PermanentNotificationDeliveryException('Recipient has no email address.');
        }

        Mail::to($notifiable->getAttribute('email'))->send(new class($notification) extends Mailable
            {
                public function __construct(private readonly AppNotification $notification) {}

                public function build(): Mailable
                {
                    return $this->subject($this->notification->title)
                        ->html('<p style="white-space:pre-line">'.nl2br(e($this->notification->body)).'</p>');
                }
            });
    }
}
