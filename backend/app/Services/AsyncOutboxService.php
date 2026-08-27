<?php

namespace App\Services;

use App\Jobs\ProcessAsyncOutboxEventJob;
use App\Models\AsyncOutboxEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class AsyncOutboxService
{
    public const TYPE_NOTIFICATION_BROADCAST = 'notification.broadcast';

    public const TYPE_NOTIFICATION_DELIVERY = 'notification.delivery';

    public const TYPE_REPORT_MESSAGE_BROADCAST = 'report_message.broadcast';

    public const TYPE_STAFF_AUDIENCE_NOTIFICATION = 'notification.staff_audience';

    /**
     * Persist intent before the surrounding business transaction commits. The queued nudge is
     * only an acceleration path; the scheduled dispatcher recovers any committed row if the PHP
     * process dies between commit and queue dispatch.
     */
    public function record(string $type, Model $aggregate, array $payload, string $dedupeKey): AsyncOutboxEvent
    {
        try {
            $event = AsyncOutboxEvent::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'type' => $type,
                    'aggregate_type' => $aggregate->getMorphClass(),
                    'aggregate_id' => $aggregate->getKey(),
                    'payload' => $payload,
                    'status' => AsyncOutboxEvent::STATUS_PENDING,
                    'available_at' => now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $event = AsyncOutboxEvent::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        if ($event->status !== AsyncOutboxEvent::STATUS_PROCESSED) {
            ProcessAsyncOutboxEventJob::dispatch($event->id)->afterCommit();
        }

        return $event;
    }
}
