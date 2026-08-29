<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ledger_transaction_id',
        'bank_account_id',
        'sequence',
        'direction',
        'amount_millimes',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount_millimes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
