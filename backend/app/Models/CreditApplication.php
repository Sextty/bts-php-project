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

    public const STATUS_CANCELLED = 'CANCELLED';

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
        // A cancellation is a terminal branch of the customer zone (never reachable after
        // FINAL_LOCKED), appended at the end like the other branch endpoints so the ordering of
        // the progression statuses stays untouched.
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'user_id',
        'status',
        'submitted_at',
        'rejection_reason',
        'decided_by_staff_user_id',
        'decided_by_admin_user_id',
        'branch_id',
        'report_closed_at',
        'report_closed_by_staff_id',
        'report_closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'report_closed_at' => 'datetime',
        ];
    }

    public function reportClosedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'report_closed_by_staff_id');
    }

    public function isReportClosed(): bool
    {
        return $this->report_closed_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The branch this application is routed to, set at submission time (CreditApplicationService
     * persists BranchMatchingService's result). The column is the basis of branch isolation:
     * branch-assigned staff see only applications whose branch_id matches their own.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The staff-facing list scope. Branch-assigned (non-superuser) staff only ever get rows
     * routed to their branch; everyone else is unfiltered. Uses a scope so every staff list —
     * review queue, report inbox — inherits the same rule by construction.
     */
    public function scopeAccessibleToStaff($query, StaffUser $staff)
    {
        if (! $staff->isBranchRestricted()) {
            return $query;
        }

        return $query->where('branch_id', $staff->branch_id);
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

    /**
     * True once the report chat is open — for confirmed appointments, locked appointments,
     * cancellations, or any application with active dialogue.
     */
    public function isReportOpen(): bool
    {
        return $this->isCancelled()
            || in_array($this->status, [
                self::STATUS_STAFF_APPROVED,
                self::STATUS_APPROVED,
                self::STATUS_APPOINTMENT_PROPOSED,
                self::STATUS_APPOINTMENT_CONFIRMED,
                self::STATUS_APPOINTMENT_LOCKED,
            ], true)
            || $this->reportMessages()->exists();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
