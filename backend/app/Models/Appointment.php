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

    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'credit_application_id',
        'branch_id',
        'attempt_number',
        'scheduled_date',
        'scheduled_time',
        'status',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'attempt_number' => 'integer',
            'decided_at' => 'datetime',
        ];
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
