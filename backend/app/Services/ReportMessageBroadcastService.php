<?php

namespace App\Services;

use App\Models\ReportMessage;

/**
 * Records realtime intent in the transactional outbox. The worker performs the actual broadcast,
 * so a Reverb outage is retried and visible without delaying or rolling back the HTTP request.
 */
class ReportMessageBroadcastService
{
    public function __construct(private readonly AsyncOutboxService $outbox) {}

    public function send(ReportMessage $message): void
    {
        $this->outbox->record(
            AsyncOutboxService::TYPE_REPORT_MESSAGE_BROADCAST,
            $message,
            [],
            'report-message-broadcast-'.$message->id,
        );
    }
}
