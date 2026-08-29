<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use InvalidArgumentException;

/**
 * Maps a configured channel name (config/services.php notifications.channels) to its delivery
 * class. DeliverNotificationJob resolves channels by name through here, so the job stays a dumb
 * fan-out worker and channels stay swappable in one place. The 'in-app' channel is deliberately
 * NOT in this registry — it is delivered synchronously by NotificationService via the
 * NotificationSent queued broadcast event (same pattern as ReportMessageSent), so
 * realtime notifications never wait on a queue worker.
 */
class NotificationChannelRegistry
{
    /** @var array<string, class-string<NotificationChannelInterface>> */
    private const DEFAULT_CHANNELS = [
        'email' => EmailNotificationChannel::class,
        'sms' => SmsNotificationChannel::class,
    ];

    /**
     * @param  array<string, class-string<NotificationChannelInterface>>|null  $channels
     */
    public function __construct(private readonly ?array $channels = null)
    {
    }

    public function resolve(string $name): NotificationChannelInterface
    {
        $channels = $this->channels ?? self::DEFAULT_CHANNELS;

        if (! isset($channels[$name])) {
            throw new InvalidArgumentException("Unknown notification channel [{$name}].");
        }

        $class = $channels[$name];

        return app($class);
    }
}
