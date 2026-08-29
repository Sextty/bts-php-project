<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A mutable operational instruction, deliberately separate from the immutable
 * accounting ledger. Only a pending request may become approved or rejected.
 */
class BankingTransferRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reference',
        'idempotency_key',
        'request_hash',
        'source_account_id',
        'destination_account_id',
        'amount_millimes',
        'currency',
        'status',
        'requested_by_staff_user_id',
        'checked_by_staff_user_id',
        'ledger_transaction_id',
        'approved_at',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_millimes' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if ($request->status !== self::STATUS_PENDING
                || $request->checked_by_staff_user_id !== null
                || $request->ledger_transaction_id !== null
                || $request->approved_at !== null
                || $request->rejected_at !== null
                || $request->rejection_reason !== null) {
                throw new LogicException('A banking transfer request must be created as a clean pending request.');
            }
        });

        static::updating(function (self $request): void {
            $allowed = [
                'status',
                'checked_by_staff_user_id',
                'ledger_transaction_id',
                'approved_at',
                'rejected_at',
                'rejection_reason',
                'updated_at',
            ];

            if (array_diff(array_keys($request->getDirty()), $allowed) !== []) {
                throw new LogicException('Banking transfer request details are immutable after creation.');
            }

            if ($request->getOriginal('status') !== self::STATUS_PENDING
                || ! in_array($request->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                throw new LogicException('A banking transfer request may only transition once from pending.');
            }

            if ($request->status === self::STATUS_APPROVED
                && ($request->checked_by_staff_user_id === null
                    || $request->ledger_transaction_id === null
                    || $request->approved_at === null
                    || $request->rejected_at !== null
                    || $request->rejection_reason !== null)) {
                throw new LogicException('An approved transfer request must contain its checker and immutable ledger transaction.');
            }

            if ($request->status === self::STATUS_REJECTED
                && ($request->checked_by_staff_user_id === null
                    || $request->rejected_at === null
                    || $request->rejection_reason === null
                    || $request->ledger_transaction_id !== null
                    || $request->approved_at !== null)) {
                throw new LogicException('A rejected transfer request must contain its checker and rejection reason only.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Banking transfer requests cannot be deleted.'));
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'destination_account_id');
    }

    public function requestedByStaffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'requested_by_staff_user_id');
    }

    public function checkedByStaffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'checked_by_staff_user_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
