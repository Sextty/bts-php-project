<?php

namespace App\Contracts;

use App\Models\AppNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery medium for an already-persisted notification. Each channel is small and focused:
 * build a recipient representation for a notifiable, then deliver one notification through the
 * medium. Implementations must never throw on delivery failure — a dead SMTP/email/SMS gateway
 * must not break the in-app notification that was already persisted. Failures are logged inside
 * each channel and swallowed; the in-app row remains the source of truth.
 */
interface NotificationChannelInterface
{
    /** A channel may not be able to reach a given recipient (e.g. SMS without a phone number). */
    public function canReach(Model $notifiable): bool;

    public function deliver(Model $notifiable, AppNotification $notification): void;
}