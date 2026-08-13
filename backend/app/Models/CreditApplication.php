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

    public const STATUS_STAFF_APPROVED = 'STAFF_APPROVED';

    public const STATUS_STAFF_REJECTED = 'STAFF_REJECTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPOINTMENT_PROPOSED = 'APPOINTMENT_PROPOSED';

    public const STATUS_APPOINTMENT_CONFIRMED = 'APPOINTMENT_CONFIRMED';

    public const STATUS_APPOINTMENT_LOCKED = 'APPOINTMENT_LOCKED';

    /**
     * Order matters: index = how far the application has progressed. STAFF_REJECTED/REJECTED
     * are branch endpoints rather than "further along" in a strict sense, but their exact
     * position relative to STAFF_APPROVED/APPROVED is irrelevant — hasReached()/isLocked() only
     * ever compare against thresholds earlier in the flow (e.g. "has this at least reached
     * SUBMITTED"), never against a sibling branch. Same reasoning covers the three appointment
     * states appended here — REJECTED/STAFF_REJECTED applications never reach them.
     */
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
        self::STATUS_STAFF_APPROVED,
        self::STATUS_STAFF_REJECTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_APPOINTMENT_PROPOSED,
        self::STATUS_APPOINTMENT_CONFIRMED,
        self::STATUS_APPOINTMENT_LOCKED,
    ];

    protected $fillable = [
        'user_id',
        'status',
        'submitted_at',
        'rejection_reason',
        'decided_by_staff_user_id',
        'decided_by_admin_user_id',
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

    public function decidedByStaffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by_staff_user_id');
    }

    public function decidedByAdminUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by_admin_user_id');
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

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** The most recent proposal — the one currently awaiting (or having received) a decision. */
    public function latestAppointment(): ?Appointment
    {
        return $this->appointments()->latest('attempt_number')->first();
    }

    public function reportMessages(): HasMany
    {
        return $this->hasMany(ReportMessage::class)->orderBy('created_at');
    }

    /**
     * True from FINAL_LOCKED onward — including every review state added after SUBMITTED. Uses
     * hasReached() rather than an explicit status list so a future status appended to
     * STATUS_ORDER is locked by default instead of silently falling through as editable, which
     * an explicit in_array() list would do (caught before shipping: the review states added here
     * would have left already-decided applications editable by the customer).
     */
    public function isLocked(): bool
    {
        return $this->hasReached(self::STATUS_FINAL_LOCKED);
    }

    /** True if $status is at or beyond $threshold in the state machine's fixed order. */
    public function hasReached(string $threshold): bool
    {
        return array_search($this->status, self::STATUS_ORDER, true) >= array_search($threshold, self::STATUS_ORDER, true);
    }
}
