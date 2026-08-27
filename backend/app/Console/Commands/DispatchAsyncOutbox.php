<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAsyncOutboxEventJob;
use App\Models\AsyncOutboxEvent;
use Illuminate\Console\Command;

class DispatchAsyncOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=500 : Maximum ready events to enqueue} {--include-failed : Explicitly replay terminal failures}';

    protected $description = 'Enqueue committed pending/retrying asynchronous outbox events';

    public function handle(): int
    {
        $limit = max(1, min(10_000, (int) $this->option('limit')));
        $queued = 0;

        AsyncOutboxEvent::query()
            ->where('status', AsyncOutboxEvent::STATUS_PROCESSING)
            ->where('started_at', '<=', now()->subSeconds((int) config('operations.outbox.processing_timeout_seconds', 120)))
            ->update([
                'status' => AsyncOutboxEvent::STATUS_RETRYING,
                'available_at' => now(),
                'last_error' => 'StaleProcessingLease',
            ]);

        $statuses = [
            AsyncOutboxEvent::STATUS_PENDING,
            AsyncOutboxEvent::STATUS_RETRYING,
        ];

        if ($this->option('include-failed')) {
            $statuses[] = AsyncOutboxEvent::STATUS_FAILED;
        }

        AsyncOutboxEvent::query()
            ->whereIn('status', $statuses)
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$queued): void {
                ProcessAsyncOutboxEventJob::dispatch($id);
                $queued++;
            });

        $this->info("Queued {$queued} asynchronous outbox event(s).");

        return self::SUCCESS;
    }
}
