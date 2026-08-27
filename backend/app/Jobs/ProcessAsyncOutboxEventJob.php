<?php

namespace App\Jobs;

use App\Events\NotificationSent;
use App\Events\ReportMessageSent;
use App\Models\AppNotification;
use App\Models\AsyncOutboxEvent;
use App\Models\ReportMessage;
use App\Services\AsyncOutboxService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessAsyncOutboxEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [2, 10, 30, 120, 300];

    public function __construct(public readonly int $outboxEventId) {}

    public function uniqueId(): string
    {
        return 'async-outbox-'.$this->outboxEventId;
    }

    public function handle(NotificationService $notifications): void
    {
        $started = hrtime(true);
        $event = DB::transaction(function () {
            $locked = AsyncOutboxEvent::query()->whereKey($this->outboxEventId)->lockForUpdate()->first();

            if (! $locked || $locked->status === AsyncOutboxEvent::STATUS_PROCESSED) {
                return null;
            }

            $locked->update([
                'status' => AsyncOutboxEvent::STATUS_PROCESSING,
                'attempts' => $locked->attempts + 1,
                'started_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'queue_delay_ms' => max(0, (int) round($locked->created_at->diffInMilliseconds(now()))),
            ]);

            return $locked->fresh();
        });

        if (! $event) {
            return;
        }

        try {
            $this->process($event, $notifications);

            $event->update([
                'status' => AsyncOutboxEvent::STATUS_PROCESSED,
                'processed_at' => now(),
                'runtime_ms' => max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'status' => AsyncOutboxEvent::STATUS_RETRYING,
                'available_at' => now()->addSeconds($this->retryDelay()),
                'runtime_ms' => max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
                'last_error' => class_basename($exception),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        AsyncOutboxEvent::query()->whereKey($this->outboxEventId)->update([
            'status' => AsyncOutboxEvent::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => $exception ? class_basename($exception) : 'UnknownOutboxFailure',
        ]);
    }

    private function process(AsyncOutboxEvent $event, NotificationService $notifications): void
    {
        match ($event->type) {
            AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST => $this->broadcastNotification($event),
            AsyncOutboxService::TYPE_NOTIFICATION_DELIVERY => $this->dispatchNotificationDelivery($event),
            AsyncOutboxService::TYPE_REPORT_MESSAGE_BROADCAST => $this->broadcastReportMessage($event),
            AsyncOutboxService::TYPE_STAFF_AUDIENCE_NOTIFICATION => $this->notifyStaffAudience($event, $notifications),
            default => throw new RuntimeException('Unsupported async outbox event type.'),
        };
    }

    private function broadcastNotification(AsyncOutboxEvent $event): void
    {
        $notification = AppNotification::query()->findOrFail($event->aggregate_id);
        broadcast(new NotificationSent($notification));
    }

    private function dispatchNotificationDelivery(AsyncOutboxEvent $event): void
    {
        $channel = $event->payload['channel'] ?? null;
        if (! is_string($channel) || $channel === '') {
            throw new RuntimeException('Outbox notification channel is missing.');
        }

        DeliverNotificationJob::dispatch($event->aggregate_id, $channel);
    }

    private function broadcastReportMessage(AsyncOutboxEvent $event): void
    {
        $message = ReportMessage::query()->findOrFail($event->aggregate_id);
        broadcast(new ReportMessageSent($message));
    }

    private function notifyStaffAudience(AsyncOutboxEvent $event, NotificationService $notifications): void
    {
        $payload = $event->payload;
        foreach (['type', 'title', 'body'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                throw new RuntimeException("Outbox staff audience {$field} is missing.");
            }
        }

        $notifications->notifyStaff(
            $payload['type'],
            $payload['title'],
            $payload['body'],
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            isset($payload['dedupeKey']) && is_string($payload['dedupeKey']) ? $payload['dedupeKey'] : null,
        );
    }

    private function retryDelay(): int
    {
        $attempt = max(1, $this->attempts());

        return $this->backoff[min($attempt - 1, count($this->backoff) - 1)];
    }
}
