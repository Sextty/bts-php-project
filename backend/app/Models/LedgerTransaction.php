<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class LedgerTransaction extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'reference',
        'idempotency_key',
        'request_hash',
        'transaction_type',
        'status',
        'currency',
        'description',
        'initiated_by_user_id',
        'initiated_by_staff_user_id',
        'metadata',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted ledger transactions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted ledger transactions cannot be deleted.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function initiatedByStaffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'initiated_by_staff_user_id');
    }
}
