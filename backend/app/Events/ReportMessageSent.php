<?php

namespace App\Events;

use App\Http\Resources\ReportMessageResource;
use App\Models\ReportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow (not ShouldBroadcast): broadcasts synchronously within the request instead
 * of via a queued job, so this works without a queue worker running — QUEUE_CONNECTION here is
 * 'database', and nothing else in this app currently requires `queue:work` to be running.
 */
class ReportMessageSent implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public ReportMessage $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // PrivateChannel prepends "private-" itself, so the bare name is passed — the resulting
        // channel is "private-application.{id}.report", which matches the pattern registered in
        // routes/channels.php. A channel without that prefix would be PUBLIC in Reverb.
        return [new PrivateChannel("application.{$this->message->credit_application_id}.report")];
    }

    public function broadcastAs(): string
    {
        return 'report.message';
    }

    public function broadcastWith(): array
    {
        return (new ReportMessageResource($this->message))->resolve();
    }
}
