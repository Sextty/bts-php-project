<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMessage extends Model
{
    public const UPDATED_AT = null;

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_STAFF = 'staff';

    protected $fillable = [
        'credit_application_id',
        'sender_type',
        'user_id',
        'staff_user_id',
        'body',
        'attachment_path',
        'attachment_name',
        'attachment_type',
        'attachment_size',
    ];

    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path);
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }
}
