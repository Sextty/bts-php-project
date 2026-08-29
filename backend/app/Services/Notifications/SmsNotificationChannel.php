<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Contracts\SmsProviderInterface;
use App\Exceptions\Notifications\PermanentNotificationDeliveryException;
use App\Models\AppNotification;
use App\Models\User;
use App\ValueObjects\OtpMessage;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SMS channel, delivered through the configured SMS provider (the same SmsProviderInterface the
 * OTP flow uses). The interface's send() targets a User and expects an OtpMessage, so the
 * notification body is adapted into that shape — provider substitution stays a one-line change
 * in AppServiceProvider. When no real provider is configured (email/log), canReach returns
 * false and the channel silently skips, exactly like OTP does in the same configuration.
 */
class SmsNotificationChannel implements NotificationChannelInterface
{
    public function __construct(private readonly SmsProviderInterface $provider) {}

    public function canReach(Model $notifiable): bool
    {
        return $notifiable instanceof User
            && $notifiable->phone !== null
            && $notifiable->phone !== ''
            && $this->provider->canReach($notifiable);
    }

    public function deliver(Model $notifiable, AppNotification $notification): void
    {
        if (! $this->canReach($notifiable)) {
            throw new PermanentNotificationDeliveryException('Recipient is unreachable through the configured SMS provider.');
        }

        $message = new OtpMessage('', 0, $notification->body);

        $result = $this->provider->send($notifiable, $message);

        if (! $result->ok) {
            throw new RuntimeException('SMS provider did not confirm delivery.');
        }
    }
}
