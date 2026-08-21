<?php

namespace App\Events;

use App\Enums\Role;
use App\Http\Resources\AppNotificationResource;
use App\Models\AppNotification;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * In-app delivery of a persisted notification over the Reverb WebSocket. ShouldBroadcastNow,
 * mirroring ReportMessageSent, so it fires synchronously without a queue worker running.
 *
 * Channel selection mirrors server-side channel authorization (routes/channels.php): a customer
 * gets their own private-user.{id} channel, a staff member gets private-staff, an admin gets
 * private-admin. The recipient never hears about notifications on channels they cannot
 * subscribe to, and channel authorization is the server-side gate in both directions.
 */
class NotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AppNotification $notification) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $notifiable = $this->notification->notifiable;

        if ($notifiable instanceof StaffUser) {
            if ($notifiable->isAtLeast(Role::Admin->value)) {
                // PrivateChannel prepends "private-" itself, so the bare name is passed here —
                // the resulting channel is "private-admin", matching routes/channels.php.
                return [new PrivateChannel('admin')];
            }

            return [new PrivateChannel('staff')];
        }

        if ($notifiable instanceof User) {
            return [new PrivateChannel("user.{$notifiable->id}")];
        }

        return [new Channel('user.0')];
    }

    public function broadcastAs(): string
    {
        return 'notification.sent';
    }

    public function broadcastWith(): array
    {
        return (new AppNotificationResource($this->notification))->resolve();
    }
}
