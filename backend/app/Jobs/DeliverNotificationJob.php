<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Services\Notifications\NotificationChannelRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out of one persisted notification through one delivery channel. Queued so email/SMS
 * delivery never runs inside the request, and unique per (notification, channel) so a redelivery
 * (queue retry, worker restart, duplicate dispatch) can never send the same notification twice
 * on the same channel. The in-app row already exists before this job is dispatched — if the
 * worker dies, the notification is still visible via the API; only the extra channel may lag.
 */
class DeliverNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(
        public readonly int $notificationId,
        public readonly string $channel,
    ) {}

    public function uniqueId(): string
    {
        return "notification-{$this->notificationId}-{$this->channel}";
    }

    public function handle(NotificationChannelRegistry $channels): void
    {
        $notification = AppNotification::find($this->notificationId);

        if (! $notification) {
            Log::warning('[notification] delivery skipped — row no longer exists', [
                'notification_id' => $this->notificationId,
                'channel' => $this->channel,
            ]);

            return;
        }

        $notifiable = $notification->notifiable;

        if (! $notifiable) {
            Log::warning('[notification] delivery skipped — notifiable no longer exists', [
                'notification_id' => $this->notificationId,
                'channel' => $this->channel,
            ]);

            return;
        }

        try {
            $channels->resolve($this->channel)->deliver($notifiable, $notification);
        } catch (\Throwable $e) {
            // A delivery failure on an extra channel must never fail the job into a retry storm
            // or take the in-app row down — log and swallow, the row stays as the source of truth.
            Log::warning('[notification] channel delivery failed', [
                'notification_id' => $this->notificationId,
                'channel' => $this->channel,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}