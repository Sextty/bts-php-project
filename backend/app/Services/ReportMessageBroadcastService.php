<?php

namespace App\Services;

use App\Events\ReportMessageSent;
use App\Models\ReportMessage;
use Illuminate\Support\Facades\Log;

/**
 * Fires ReportMessageSent without letting a dead realtime server break message sending. The
 * message row is already committed by the caller; live push is a nice-to-have, so a Reverb/Pusher
 * outage degrades to "chat still works, no instant delivery" instead of a 500 on every send.
 */
class ReportMessageBroadcastService
{
    public function send(ReportMessage $message): void
    {
        try {
            broadcast(new ReportMessageSent($message));
        } catch (\Throwable $e) {
            Log::warning('[report-chat] realtime broadcast failed', [
                'message_id' => $message->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
