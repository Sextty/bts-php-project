<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotificationJob;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

class ReconcileNotificationDeliveries extends Command
{
    protected $signature = 'notifications:reconcile {--limit=500 : Maximum deliveries to replay} {--include-permanent : Replay permanent failures after their cause was corrected}';

    protected $description = 'Replay unfinished external deliveries for persisted in-app notifications';

    public function handle(): int
    {
        $statuses = [NotificationDelivery::STATUS_PENDING, NotificationDelivery::STATUS_RETRYING, NotificationDelivery::STATUS_FAILED];
        if ($this->option('include-permanent')) {
            $statuses[] = NotificationDelivery::STATUS_PERMANENT_FAILED;
        }

        $deliveries = NotificationDelivery::query()
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->limit(max(1, min(10_000, (int) $this->option('limit'))))
            ->get();

        foreach ($deliveries as $delivery) {
            $delivery->update(['status' => NotificationDelivery::STATUS_PENDING, 'failed_at' => null]);
            DeliverNotificationJob::dispatch($delivery->app_notification_id, $delivery->channel)->afterCommit();
        }

        $this->info("Queued {$deliveries->count()} notification delivery reconciliation job(s).");

        return self::SUCCESS;
    }
}
