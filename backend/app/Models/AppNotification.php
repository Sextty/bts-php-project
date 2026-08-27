<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * In-app notification (distinct from Illuminate\Notifications\Notification on purpose — naming
 * it AppNotification avoids the clash). One row per logical notification; delivery over each
 * configured channel (email/SMS/WebSocket) is handled by NotificationService and its jobs.
 */
class AppNotification extends Model
{
    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'title',
        'body',
        'data',
        'dedupe_key',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function notifiable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForNotifiable(Builder $query, Model $notifiable): Builder
    {
        return $query->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey());
    }
}
