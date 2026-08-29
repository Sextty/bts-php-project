<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** Initial automatic proposal plus four successful customer reschedules. */
    public const MAX_RESCHEDULES = 4;

    public const MAX_ATTEMPTS = self::MAX_RESCHEDULES + 1;

    protected $fillable = [
        'credit_application_id',
        'branch_id',
        'attempt_number',
        'reschedule_count',
        'scheduled_date',
        'scheduled_time',
        'status',
        'is_auto_scheduled_future',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'attempt_number' => 'integer',
            'reschedule_count' => 'integer',
            'is_auto_scheduled_future' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function isAutoScheduledFuture(): bool
    {
        return (bool) $this->is_auto_scheduled_future;
    }

    public function rescheduleCount(): int
    {
        return max(0, (int) $this->reschedule_count);
    }

    public function remainingReschedules(): int
    {
        return max(0, self::MAX_RESCHEDULES - $this->rescheduleCount());
    }

    public function canSelfReschedule(): bool
    {
        return $this->status === self::STATUS_PROPOSED && $this->remainingReschedules() > 0;
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
