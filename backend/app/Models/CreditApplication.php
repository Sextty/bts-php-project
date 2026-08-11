<?php

namespace App\Models;

use Database\Factories\CreditApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditApplication extends Model
{
    /** @use HasFactory<CreditApplicationFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_STEP_1_COMPLETED = 'STEP_1_COMPLETED';

    public const STATUS_STEP_2_COMPLETED = 'STEP_2_COMPLETED';

    public const STATUS_STEP_3_COMPLETED = 'STEP_3_COMPLETED';

    public const STATUS_READY_FOR_VALIDATION_1 = 'READY_FOR_VALIDATION_1';

    public const STATUS_VALIDATION_1_COMPLETED = 'VALIDATION_1_COMPLETED';

    public const STATUS_VALIDATION_2 = 'VALIDATION_2';

    public const STATUS_FINAL_LOCKED = 'FINAL_LOCKED';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    /** Order matters: index = how far the application has progressed. */
    public const STATUS_ORDER = [
        self::STATUS_DRAFT,
        self::STATUS_STEP_1_COMPLETED,
        self::STATUS_STEP_2_COMPLETED,
        self::STATUS_STEP_3_COMPLETED,
        self::STATUS_READY_FOR_VALIDATION_1,
        self::STATUS_VALIDATION_1_COMPLETED,
        self::STATUS_VALIDATION_2,
        self::STATUS_FINAL_LOCKED,
        self::STATUS_SUBMITTED,
    ];

    protected $fillable = [
        'user_id',
        'status',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function creditRequest(): HasOne
    {
        return $this->hasOne(CreditRequest::class);
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function validationSteps(): HasMany
    {
        return $this->hasMany(ValidationStep::class);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_FINAL_LOCKED, self::STATUS_SUBMITTED], true);
    }

    /** True if $status is at or beyond $threshold in the state machine's fixed order. */
    public function hasReached(string $threshold): bool
    {
        return array_search($this->status, self::STATUS_ORDER, true) >= array_search($threshold, self::STATUS_ORDER, true);
    }
}
