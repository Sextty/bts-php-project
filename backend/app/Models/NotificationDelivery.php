<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PERMANENT_FAILED = 'permanent_failed';

    protected $fillable = [
        'app_notification_id', 'channel', 'status', 'attempts', 'last_error',
        'delivered_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class, 'app_notification_id');
    }
}
