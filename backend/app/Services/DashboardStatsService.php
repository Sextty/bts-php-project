<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the admin overview. Every figure is derived from credit_applications and audit_logs
 * at read time — nothing is denormalized into a counters table, because the volumes here are a
 * bank branch's caseload (hundreds), not an analytics workload, and a stale counter is worse than
 * a cheap COUNT.
 */
class DashboardStatsService
{
    /** Statuses that mean the customer is still filling the wizard, not yet in a staff queue. */
    private const IN_PROGRESS = [
        CreditApplication::STATUS_DRAFT,
        CreditApplication::STATUS_STEP_1_COMPLETED,
        CreditApplication::STATUS_STEP_2_COMPLETED,
        CreditApplication::STATUS_STEP_3_COMPLETED,
        CreditApplication::STATUS_READY_FOR_VALIDATION_1,
        CreditApplication::STATUS_VALIDATION_1_COMPLETED,
        CreditApplication::STATUS_VALIDATION_2,
        CreditApplication::STATUS_FINAL_LOCKED,
    ];

    /** Post-decision statuses, including the appointment states that follow an approval. */
    private const APPROVED = [
        CreditApplication::STATUS_APPROVED,
        CreditApplication::STATUS_APPOINTMENT_PROPOSED,
        CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
        CreditApplication::STATUS_APPOINTMENT_LOCKED,
    ];

    private const REJECTED = [
        CreditApplication::STATUS_STAFF_REJECTED,
        CreditApplication::STATUS_REJECTED,
    ];

    /** The audit actions that mark a human decision, used for timing, timeline and team stats. */
    private const DECISION_ACTIONS = [
        'credit_application.staff_approved',
        'credit_application.admin_approved',
        'credit_application.staff_rejected',
        'credit_application.admin_rejected',
    ];

    public function build(int $timelineDays = 30): array
    {
        return [
            'kpis' => $this->kpis(),
            'pipeline' => $this->pipeline(),
            'timeline' => $this->timeline($timelineDays),
            'team' => $this->team(),
        ];
    }

    private function kpis(): array
    {
        // One grouped query rather than a COUNT per status — the status column is the only thing
        // being bucketed, so there's no reason to hit the table a dozen times.
        $byStatus = CreditApplication::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $sum = fn (array $statuses) => collect($statuses)->sum(fn ($s) => (int) ($byStatus[$s] ?? 0));

        $approved = $sum(self::APPROVED);
        $rejected = $sum(self::REJECTED);
        $decided = $approved + $rejected;

        return [
            'total' => (int) $byStatus->sum(),
            'in_progress' => $sum(self::IN_PROGRESS),
            'awaiting_staff' => (int) ($byStatus[CreditApplication::STATUS_SUBMITTED] ?? 0),
            'awaiting_admin' => (int) ($byStatus[CreditApplication::STATUS_STAFF_APPROVED] ?? 0),
            'approved' => $approved,
            'rejected' => $rejected,
            // Share of *decided* files that were approved. Undecided files are excluded so the
            // rate doesn't drift down simply because new applications keep arriving.
            'approval_rate' => $decided > 0 ? round($approved / $decided * 100, 1) : null,
            'avg_decision_hours' => $this->averageDecisionHours(),
        ];
    }

    /**
     * Mean hours between a customer submitting and the first staff/admin decision. There is no
     * decision timestamp column on credit_applications, so the moment is taken from the audit
     * trail — which is the authoritative record of when a decision was actually recorded.
     *
     * The averaging is done in PHP rather than SQL: date arithmetic is one of the few places
     * MySQL (production) and SQLite (test suite) genuinely disagree, and a caseload of hundreds
     * doesn't justify pushing it into the database. Null until something has been decided, so the
     * UI shows "—" instead of a misleading 0.
     */
    private function averageDecisionHours(): ?float
    {
        $decidedAt = AuditLog::query()
            ->whereNotNull('credit_application_id')
            ->whereIn('action', self::DECISION_ACTIONS)
            ->select('credit_application_id', 'created_at')
            ->get()
            ->groupBy('credit_application_id')
            ->map(fn ($rows) => $rows->min('created_at'));

        if ($decidedAt->isEmpty()) {
            return null;
        }

        $submitted = CreditApplication::query()
            ->whereIn('id', $decidedAt->keys())
            ->whereNotNull('submitted_at')
            ->pluck('submitted_at', 'id');

        $spans = $submitted
            ->map(fn ($submittedAt, $id) => Carbon::parse($submittedAt)
                ->diffInMinutes(Carbon::parse($decidedAt[$id]), absolute: true))
            ->filter(fn ($m) => $m !== null);

        return $spans->isEmpty() ? null : round($spans->avg() / 60, 1);
    }

    /** Every status with a non-zero count, ordered by the real lifecycle rather than alphabetically. */
    private function pipeline(): array
    {
        $counts = CreditApplication::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(CreditApplication::STATUS_ORDER)
            ->filter(fn ($status) => ($counts[$status] ?? 0) > 0)
            ->map(fn ($status) => ['status' => $status, 'count' => (int) $counts[$status]])
            ->values()
            ->all();
    }

    /**
     * Daily created/approved/rejected counts. Days with no activity are filled with zeros so the
     * chart keeps a constant x-axis step — without this, a quiet week would compress visually and
     * misrepresent the trend.
     */
    private function timeline(int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $created = CreditApplication::query()
            ->where('created_at', '>=', $from)
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $decisions = AuditLog::query()
            ->where('created_at', '>=', $from)
            ->whereIn('action', self::DECISION_ACTIONS)
            ->select('action', DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy('action', 'day')
            ->get();

        $approvedByDay = $decisions->filter(fn ($r) => str_contains($r->action, 'approved'))
            ->groupBy('day')->map(fn ($rows) => $rows->sum('total'));
        $rejectedByDay = $decisions->filter(fn ($r) => str_contains($r->action, 'rejected'))
            ->groupBy('day')->map(fn ($rows) => $rows->sum('total'));

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $out[] = [
                'date' => $day,
                'created' => (int) ($created[$day] ?? 0),
                'approved' => (int) ($approvedByDay[$day] ?? 0),
                'rejected' => (int) ($rejectedByDay[$day] ?? 0),
            ];
        }

        return $out;
    }

    /** Decisions recorded per staff member, so the admin can see how work is distributed. */
    private function team(): array
    {
        $rows = AuditLog::query()
            ->whereNotNull('staff_user_id')
            ->whereIn('action', self::DECISION_ACTIONS)
            ->select('staff_user_id', 'action', DB::raw('COUNT(*) as total'))
            ->groupBy('staff_user_id', 'action')
            ->get();

        $staff = StaffUser::query()
            ->whereIn('id', $rows->pluck('staff_user_id')->unique())
            ->get()
            ->keyBy('id');

        return $rows->groupBy('staff_user_id')
            ->map(function ($actions, $staffId) use ($staff) {
                $member = $staff[$staffId] ?? null;

                return [
                    'staff_user_id' => (int) $staffId,
                    'name' => $member ? trim($member->first_name.' '.$member->last_name) : 'Compte supprimé',
                    'role' => $member?->role,
                    'approvals' => (int) $actions->filter(fn ($a) => str_contains($a->action, 'approved'))->sum('total'),
                    'rejections' => (int) $actions->filter(fn ($a) => str_contains($a->action, 'rejected'))->sum('total'),
                ];
            })
            ->sortByDesc(fn ($m) => $m['approvals'] + $m['rejections'])
            ->values()
            ->all();
    }
}
