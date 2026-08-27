<?php

namespace App\Contracts;

use App\Models\AppNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery medium for an already-persisted notification. Each channel is small and focused:
 * build a recipient representation for a notifiable, then deliver one notification through the
 * medium. Implementations throw retryable infrastructure failures and use a permanent-delivery
 * exception for recipient/configuration failures. The queued job records/retries them; the
 * already-persisted in-app source row is never rolled back.
 */
interface NotificationChannelInterface
{
    /** A channel may not be able to reach a given recipient (e.g. SMS without a phone number). */
    public function canReach(Model $notifiable): bool;

    public function deliver(Model $notifiable, AppNotification $notification): void;
}
