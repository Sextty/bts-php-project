<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationStep extends Model
{
    public const UPDATED_AT = null;

    public const STEP_VALIDATION_1 = 'validation_1';

    public const STEP_VALIDATION_2 = 'validation_2';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'credit_application_id',
        'step',
        'status',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
        ];
    }

    public function creditApplication(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class);
    }
}
