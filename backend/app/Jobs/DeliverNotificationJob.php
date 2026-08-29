<?php

namespace App\Jobs;

use App\Exceptions\Notifications\PermanentNotificationDeliveryException;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\NotificationDelivery;
use App\Services\AuditLogService;
use App\Services\Notifications\NotificationChannelRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Fan-out of one persisted notification through one delivery channel. Queued so email/SMS
 * delivery never runs inside the request, and unique per (notification, channel) so a redelivery
 * (queue retry, worker restart, duplicate dispatch) can never send the same notification twice
 * on the same channel. The in-app row already exists before this job is dispatched — if the
 * worker dies, the notification is still visible via the API; only the extra channel may lag.
 */
class DeliverNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

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
            Log::warning('[notification] source row missing', ['notification_id' => $this->notificationId, 'channel' => $this->channel]);

            return;
        }

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['app_notification_id' => $notification->id, 'channel' => $this->channel],
            ['status' => NotificationDelivery::STATUS_PENDING, 'attempts' => 0],
        );

        if ($delivery->status === NotificationDelivery::STATUS_DELIVERED) {
            $this->auditFinalDecisionEmailDelivery($notification);

            return;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_PROCESSING,
            'attempts' => max($delivery->attempts + 1, $this->attempts()),
            'last_error' => null,
        ]);

        $notifiable = $notification->notifiable;

        if (! $notifiable) {
            $this->markPermanentFailure($delivery, 'NotifiableMissing');

            return;
        }

        try {
            $channels->resolve($this->channel)->deliver($notifiable, $notification);
            $delivery->update([
                'status' => NotificationDelivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);
            $this->auditFinalDecisionEmailDelivery($notification);
        } catch (PermanentNotificationDeliveryException|InvalidArgumentException $e) {
            $this->markPermanentFailure($delivery, class_basename($e));
            $this->fail($e);
        } catch (Throwable $e) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_RETRYING,
                'last_error' => class_basename($e),
                'failed_at' => null,
            ]);
            Log::warning('[notification] channel delivery failed', [
                'notification_id' => $this->notificationId,
                'channel' => $this->channel,
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()
            ->where('app_notification_id', $this->notificationId)
            ->where('channel', $this->channel)
            ->first();

        if ($delivery && ! in_array($delivery->status, [
            NotificationDelivery::STATUS_DELIVERED,
            NotificationDelivery::STATUS_PERMANENT_FAILED,
        ], true)) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'last_error' => $exception ? class_basename($exception) : 'UnknownDeliveryFailure',
                'failed_at' => now(),
            ]);
        }
    }

    private function markPermanentFailure(NotificationDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => NotificationDelivery::STATUS_PERMANENT_FAILED,
            'last_error' => $reason,
            'failed_at' => now(),
        ]);

        Log::warning('[notification] permanent delivery failure', [
            'notification_id' => $this->notificationId,
            'channel' => $this->channel,
            'reason' => $reason,
        ]);
    }

    /** Idempotent audit evidence; the notification body and recipient address are not copied. */
    private function auditFinalDecisionEmailDelivery(AppNotification $notification): void
    {
        if ($this->channel !== 'email' || ! in_array($notification->type, [
            'staff.rejected',
            'admin.approved',
            'admin.rejected',
        ], true)) {
            return;
        }

        $applicationId = (int) ($notification->data['application_id'] ?? 0);
        if ($applicationId < 1 || AuditLog::query()
            ->where('credit_application_id', $applicationId)
            ->where('action', 'credit_application.final_decision_notification_sent')
            ->exists()) {
            return;
        }

        $application = CreditApplication::query()->with('user')->find($applicationId);
        if (! $application) {
            Log::warning('[notification] final decision application missing for audit', [
                'notification_id' => $notification->id,
                'application_id' => $applicationId,
            ]);

            return;
        }

        app(AuditLogService::class)->log(
            'credit_application.final_decision_notification_sent',
            $application->user,
            newState: [
                'notification_id' => $notification->id,
                'decision' => $notification->type === 'admin.approved' ? 'approved' : 'rejected',
                'channel' => 'email',
            ],
            application: $application,
        );
    }
}
