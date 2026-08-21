<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Jobs\DeliverNotificationJob;
use App\Models\AppNotification;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for creating notifications. Every notification lifecycle hook in the
 * codebase goes through one of notifyUser / notifyStaff / notifyAdmins — the service persists
 * the in-app row (the source of truth), dedupes by the business-scoped key, then fans delivery
 * out to each configured channel via DeliverNotificationJob.
 *
 * Channel policy lives in config/services.php (services.notifications.channels), so adding or
 * re-routing a channel for a type is a config change, not a code change. Types without an entry
 * default to ['in-app'].
 *
 * Deduplication: the unique index (notifiable_type, notifiable_id, type, dedupe_key) is the
 * hard guarantee; the exists() check below is the friendly path that avoids a failed insert.
 * A null dedupe_key means the type is allowed to occur repeatedly.
 */
class NotificationService
{
    public function notifyUser(User $user, string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): ?AppNotification
    {
        $notification = $this->persist($user, $type, $title, $body, $data, $dedupeKey);

        if ($notification === null) {
            return null;
        }

        $this->dispatchDeliveries($notification, $type);

        return $notification;
    }

    /**
     * Notify every active staff member. Branch filtering is deliberately NOT applied here: the
     * staff list is small and the application routing data is displayed in the payload, letting
     * the frontend filter; a branch-scoped list would silently miss cross-branch admins.
     *
     * @return list<AppNotification>
     */
    public function notifyStaff(string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): array
    {
        $created = [];

        foreach (StaffUser::where('status', 'active')->get() as $staff) {
            $notification = $this->persist($staff, $type, $title, $body, $data, $dedupeKey);

            if ($notification !== null) {
                $created[] = $notification;
            }
        }

        foreach ($created as $notification) {
            $this->dispatchDeliveries($notification, $type);
        }

        return $created;
    }

    /** Notify only staff who rank at or above admin (admins and super admins). */
    public function notifyAdmins(string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): array
    {
        $created = [];

        foreach (StaffUser::where('status', 'active')->get() as $staff) {
            if (! $staff->isAtLeast('admin')) {
                continue;
            }

            $notification = $this->persist($staff, $type, $title, $body, $data, $dedupeKey);

            if ($notification !== null) {
                $created[] = $notification;
            }
        }

        foreach ($created as $notification) {
            $this->dispatchDeliveries($notification, $type);
        }

        return $created;
    }

    private function persist(Model $notifiable, string $type, string $title, string $body, array $data, ?string $dedupeKey): ?AppNotification
    {
        if ($dedupeKey !== null) {
            $exists = AppNotification::query()
                ->forNotifiable($notifiable)
                ->where('type', $type)
                ->where('dedupe_key', $dedupeKey)
                ->exists();

            if ($exists) {
                return null;
            }
        }

        return AppNotification::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    /**
     * @return list<string>
     */
    private function channelsFor(string $type): array
    {
        // Array-key lookup on purpose: notification types contain dots ("document.rejected"),
        // and config()'s dotted-path accessor would resolve them as array hierarchy.
        $channels = config('services.notifications.channels', []);

        return $channels[$type] ?? ['in-app'];
    }

    private function dispatchDeliveries(AppNotification $notification, string $type): void
    {
        foreach ($this->channelsFor($type) as $channel) {
            if ($channel === 'in-app') {
                // In-app delivery = broadcast the already-persisted row over the socket.
                // A dead realtime server (Reverb/Pusher down) must not roll back the
                // business transaction that already committed the notification row.
                // Same pattern as ReportMessageBroadcastService.
                try {
                    NotificationSent::dispatch($notification);
                } catch (\Throwable $e) {
                    Log::warning('[notification] realtime broadcast failed', [
                        'notification_id' => $notification->id,
                        'type' => $type,
                        'exception' => $e->getMessage(),
                    ]);
                }
            } else {
                DeliverNotificationJob::dispatch($notification->id, $channel);
            }
        }
    }
}