<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly AsyncOutboxService $outbox) {}

    public function notifyUser(User $user, string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): ?AppNotification
    {
        return $this->persistAndPlan($user, $type, $title, $body, $data, $dedupeKey);
    }

    /** Persist one durable audience intent; recipient fan-out happens in a worker after commit. */
    public function queueStaffAudience(Model $aggregate, string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): void
    {
        $this->outbox->record(
            AsyncOutboxService::TYPE_STAFF_AUDIENCE_NOTIFICATION,
            $aggregate,
            compact('type', 'title', 'body', 'data', 'dedupeKey'),
            'staff-audience-'.($dedupeKey ?? $aggregate->getMorphClass().'-'.$aggregate->getKey().'-'.$type),
        );
    }

    /**
     * Notify active authorized staff. Application-scoped notifications go only to the matching
     * branch plus global admin/security roles; unassigned operational staff receive nothing.
     *
     * @return list<AppNotification>
     */
    public function notifyStaff(string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): array
    {
        $created = [];

        $recipients = StaffUser::query()->where('status', 'active');

        if (isset($data['branch_id']) && is_numeric($data['branch_id'])) {
            $branchId = (int) $data['branch_id'];
            $recipients->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereIn('role', ['admin', 'super_admin', 'security']);
            });
        }

        $recipients->select(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'branch_id'])
            ->chunkById(200, function ($staffMembers) use (&$created, $type, $title, $body, $data, $dedupeKey): void {
                foreach ($staffMembers as $staff) {
                    $notification = $this->persistAndPlan($staff, $type, $title, $body, $data, $dedupeKey);

                    if ($notification !== null) {
                        $created[] = $notification;
                    }
                }
            });

        return $created;
    }

    /** Notify only staff who rank at or above admin (admins and super admins). */
    public function notifyAdmins(string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null): array
    {
        $created = [];

        StaffUser::query()
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'super_admin'])
            ->select(['id', 'first_name', 'last_name', 'email', 'role', 'status', 'branch_id'])
            ->chunkById(200, function ($staffMembers) use (&$created, $type, $title, $body, $data, $dedupeKey): void {
                foreach ($staffMembers as $staff) {
                    $notification = $this->persistAndPlan($staff, $type, $title, $body, $data, $dedupeKey);

                    if ($notification !== null) {
                        $created[] = $notification;
                    }
                }
            });

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

        try {
            return AppNotification::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($dedupeKey !== null) {
                return null;
            }

            throw $e;
        }
    }

    private function persistAndPlan(Model $notifiable, string $type, string $title, string $body, array $data, ?string $dedupeKey): ?AppNotification
    {
        return DB::transaction(function () use ($notifiable, $type, $title, $body, $data, $dedupeKey) {
            $notification = $this->persist($notifiable, $type, $title, $body, $data, $dedupeKey);

            if ($notification === null) {
                return null;
            }

            $this->planDeliveries($notification, $type);

            return $notification;
        });
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

    private function planDeliveries(AppNotification $notification, string $type): void
    {
        foreach ($this->channelsFor($type) as $channel) {
            if ($channel === 'in-app') {
                $this->outbox->record(
                    AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST,
                    $notification,
                    [],
                    'notification-broadcast-'.$notification->id,
                );
            } else {
                NotificationDelivery::query()->firstOrCreate(
                    ['app_notification_id' => $notification->id, 'channel' => $channel],
                    ['status' => NotificationDelivery::STATUS_PENDING, 'attempts' => 0],
                );
                $this->outbox->record(
                    AsyncOutboxService::TYPE_NOTIFICATION_DELIVERY,
                    $notification,
                    ['channel' => $channel],
                    'notification-delivery-'.$notification->id.'-'.$channel,
                );
            }
        }
    }
}
