<?php

namespace App\Services\Analytics;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AnalyticsService
{
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

    private const DECISION_STATUSES = [
        CreditApplication::STATUS_STAFF_APPROVED,
        CreditApplication::STATUS_STAFF_REJECTED,
        CreditApplication::STATUS_APPROVED,
        CreditApplication::STATUS_REJECTED,
    ];

    private const DECISION_ACTIONS = [
        'credit_application.staff_approved',
        'credit_application.staff_rejected',
        'credit_application.admin_approved',
        'credit_application.admin_rejected',
    ];

    private const CANCELLATION_ACTIONS = [
        'credit_application.cancelled',
        'application.cancelled',
    ];

    /** @return array<string,mixed> */
    public function snapshot(AnalyticsFilter $filter): array
    {
        $statusCounts = $this->statusCounts($filter);

        return [
            'meta' => array_merge($filter->metadata(), [
                'generated_at' => now()->toIso8601String(),
                'scope' => $filter->branchId === null ? 'global' : 'branch',
                'currency_note' => 'Requested amounts are grouped by stored currency; no approved-amount field exists.',
            ]),
            'kpis' => $this->kpis($filter, $statusCounts),
            'timeline' => $this->applicationTimeline($filter),
            'statuses' => $statusCounts->map(fn (int $count, string $status) => [
                'status' => $status,
                'count' => $count,
            ])->values()->all(),
            'branches' => $this->branches($filter),
            'appointments' => $this->appointments($filter),
            'workflow' => $this->workflow($filter),
            'credit' => $this->credit($filter),
            'geography' => $this->geography($filter),
        ];
    }

    private function applications(AnalyticsFilter $filter): Builder
    {
        $query = DB::table('credit_applications as ca')
            ->whereNull('ca.deleted_at')
            ->where('ca.created_at', '>=', $filter->from)
            ->where('ca.created_at', '<=', $filter->to);

        if ($filter->branchId !== null) {
            $query->where('ca.branch_id', $filter->branchId);
        }
        if ($filter->status !== null) {
            $query->where('ca.status', $filter->status);
        }

        return $query;
    }

    /** @return Collection<string,int> */
    private function statusCounts(AnalyticsFilter $filter): Collection
    {
        $rows = $this->applications($filter)
            ->select('ca.status', DB::raw('COUNT(*) as total'))
            ->groupBy('ca.status')
            ->get();

        $counts = collect(CreditApplication::STATUS_ORDER)->mapWithKeys(fn (string $status) => [$status => 0]);
        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /** @param Collection<string,int> $counts */
    private function kpis(AnalyticsFilter $filter, Collection $counts): array
    {
        $sum = fn (array $statuses): int => collect($statuses)->sum(fn (string $status) => (int) ($counts[$status] ?? 0));
        $total = (int) $counts->sum();
        $approved = $sum(self::APPROVED);
        $rejected = $sum(self::REJECTED);
        $cancelled = (int) ($counts[CreditApplication::STATUS_CANCELLED] ?? 0);
        $decided = $approved + $rejected;

        $amounts = $this->applications($filter)
            ->join('credit_requests as cr', 'cr.credit_application_id', '=', 'ca.id')
            ->selectRaw('COUNT(cr.id) as applications_with_amount')
            ->selectRaw('SUM(COALESCE(cr.montant_global_sollicite, 0)) as requested_total')
            ->selectRaw('AVG(cr.montant_global_sollicite) as requested_average')
            ->first();

        return [
            'total_applications' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'cancelled' => $cancelled,
            'pending' => max(0, $total - $approved - $rejected - $cancelled),
            'approval_rate' => $decided > 0 ? round($approved * 100 / $decided, 1) : null,
            'cancellation_rate' => $total > 0 ? round($cancelled * 100 / $total, 1) : null,
            'applications_with_amount' => (int) ($amounts->applications_with_amount ?? 0),
            'requested_amount_total' => round((float) ($amounts->requested_total ?? 0), 3),
            'requested_amount_average' => $amounts?->requested_average !== null ? round((float) $amounts->requested_average, 3) : null,
            'average_processing_hours' => $this->averageDecisionHours($filter),
        ];
    }

    /** @return list<array{date:string,created:int,approved:int,rejected:int,cancelled:int}> */
    private function applicationTimeline(AnalyticsFilter $filter): array
    {
        $created = $this->applications($filter)
            ->selectRaw('DATE(ca.created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $events = $this->timelineEvents($filter)->groupBy('day');

        $result = [];
        for ($day = $filter->from->startOfDay(); $day->lessThanOrEqualTo($filter->to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $dayEvents = collect($events[$date] ?? []);
            $result[] = [
                'date' => $date,
                'created' => (int) ($created[$date] ?? 0),
                'approved' => (int) $dayEvents->where('event_type', 'approved')->sum('total'),
                'rejected' => (int) $dayEvents->where('event_type', 'rejected')->sum('total'),
                'cancelled' => (int) $dayEvents->where('event_type', 'cancelled')->sum('total'),
            ];
        }

        return $result;
    }

    /** @return Collection<int,object> */
    private function timelineEvents(AnalyticsFilter $filter): Collection
    {
        $scope = function (Builder $query) use ($filter): Builder {
            return $query
                ->join('credit_applications as ca', 'ca.id', '=', 'al.credit_application_id')
                ->whereNull('ca.deleted_at')
                ->where('al.created_at', '>=', $filter->from)
                ->where('al.created_at', '<=', $filter->to)
                ->when($filter->branchId !== null, fn ($builder) => $builder->where('ca.branch_id', $filter->branchId))
                ->when($filter->status !== null, fn ($builder) => $builder->where('ca.status', $filter->status));
        };

        $transitions = $scope(DB::table('audit_logs as al'))
            ->where('al.action', 'credit_application.status_changed')
            ->whereIn('al.analytics_status', [
                CreditApplication::STATUS_APPROVED,
                ...self::REJECTED,
                CreditApplication::STATUS_CANCELLED,
            ])
            ->selectRaw("DATE(al.created_at) as day, ca.id as application_id,
                CASE WHEN al.analytics_status = ? THEN 'approved'
                     WHEN al.analytics_status IN (?, ?) THEN 'rejected'
                     ELSE 'cancelled' END as event_type", [
                CreditApplication::STATUS_APPROVED,
                ...self::REJECTED,
            ]);

        $direct = $scope(DB::table('audit_logs as al'))
            ->whereIn('al.action', [
                'credit_application.admin_approved',
                'credit_application.staff_rejected',
                'credit_application.admin_rejected',
                ...self::CANCELLATION_ACTIONS,
            ])
            ->selectRaw("DATE(al.created_at) as day, ca.id as application_id,
                CASE WHEN al.action = ? THEN 'approved'
                     WHEN al.action IN (?, ?) THEN 'rejected'
                     ELSE 'cancelled' END as event_type", [
                'credit_application.admin_approved',
                'credit_application.staff_rejected',
                'credit_application.admin_rejected',
            ]);

        return DB::query()
            ->fromSub($transitions->unionAll($direct), 'timeline_events')
            ->selectRaw('day, event_type, COUNT(DISTINCT application_id) as total')
            ->groupBy('day', 'event_type')
            ->get();
    }

    /** @return list<array<string,mixed>> */
    private function branches(AnalyticsFilter $filter): array
    {
        $branches = Branch::query()
            ->when($filter->branchId !== null, fn ($query) => $query->whereKey($filter->branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'ville', 'delegation', 'daily_capacity']);

        $applications = $this->applications($filter)
            ->whereNotNull('ca.branch_id')
            ->selectRaw('ca.branch_id, ca.status, COUNT(*) as total')
            ->groupBy('ca.branch_id', 'ca.status')
            ->get()
            ->groupBy('branch_id');

        $appointments = $this->appointmentBase($filter)
            ->selectRaw('a.branch_id, a.status, COUNT(*) as total')
            ->groupBy('a.branch_id', 'a.status')
            ->get()
            ->groupBy('branch_id');

        $processing = $this->averageDecisionHoursByBranch($filter);

        return $branches->map(function (Branch $branch) use ($applications, $appointments, $processing) {
            $appRows = collect($applications[$branch->id] ?? []);
            $appointmentRows = collect($appointments[$branch->id] ?? []);
            $appCount = fn (array $statuses): int => (int) $appRows->filter(fn ($row) => in_array($row->status, $statuses, true))->sum('total');
            $appointmentCount = fn (array $statuses): int => (int) $appointmentRows->filter(fn ($row) => in_array($row->status, $statuses, true))->sum('total');
            $total = (int) $appRows->sum('total');
            $approved = $appCount(self::APPROVED);
            $rejected = $appCount(self::REJECTED);
            $cancelled = $appCount([CreditApplication::STATUS_CANCELLED]);

            return [
                'branch_id' => $branch->id,
                'name' => $branch->name,
                'governorate' => $branch->ville,
                'delegation' => $branch->delegation,
                'application_volume' => $total,
                'pending_workload' => max(0, $total - $approved - $rejected - $cancelled),
                'approved' => $approved,
                'rejected' => $rejected,
                'cancelled' => $cancelled,
                'average_processing_hours' => isset($processing[$branch->id]) ? round((float) $processing[$branch->id], 1) : null,
                'appointment_volume' => (int) $appointmentRows->sum('total'),
                'appointment_accepted' => $appointmentCount([Appointment::STATUS_ACCEPTED]),
                'appointment_cancelled' => $appointmentCount([Appointment::STATUS_CANCELLED, Appointment::STATUS_REJECTED]),
            ];
        })->values()->all();
    }

    private function appointmentBase(AnalyticsFilter $filter): Builder
    {
        $query = DB::table('appointments as a')
            ->where('a.scheduled_date', '>=', $filter->from->toDateString())
            ->where('a.scheduled_date', '<=', $filter->to->toDateString());

        if ($filter->branchId !== null) {
            $query->where('a.branch_id', $filter->branchId);
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function appointments(AnalyticsFilter $filter): array
    {
        $byStatus = $this->appointmentBase($filter)
            ->selectRaw('a.status, COUNT(*) as total')
            ->groupBy('a.status')
            ->pluck('total', 'status');
        $active = (int) ($byStatus[Appointment::STATUS_PROPOSED] ?? 0) + (int) ($byStatus[Appointment::STATUS_ACCEPTED] ?? 0);
        $branchCapacity = (int) Branch::query()
            ->when($filter->branchId !== null, fn ($query) => $query->whereKey($filter->branchId))
            ->sum('daily_capacity');
        $capacity = $this->workingDays($filter->from, $filter->to) * $branchCapacity;

        $timeline = $this->appointmentBase($filter)
            ->selectRaw('a.scheduled_date as date, a.status, COUNT(*) as total')
            ->groupBy('a.scheduled_date', 'a.status')
            ->orderBy('a.scheduled_date')
            ->get();

        $future = DB::table('appointments as a')
            ->whereBetween('a.scheduled_date', [now()->addDay()->toDateString(), now()->addDays(30)->toDateString()])
            ->whereIn('a.status', [Appointment::STATUS_PROPOSED, Appointment::STATUS_ACCEPTED])
            ->when($filter->branchId !== null, fn ($query) => $query->where('a.branch_id', $filter->branchId))
            ->count();

        $attempts = $this->appointmentBase($filter)
            ->selectRaw('AVG(a.attempt_number) as average_attempt, MAX(a.attempt_number) as max_attempt')
            ->first();

        return [
            'total' => (int) $byStatus->sum(),
            'proposed' => (int) ($byStatus[Appointment::STATUS_PROPOSED] ?? 0),
            'accepted' => (int) ($byStatus[Appointment::STATUS_ACCEPTED] ?? 0),
            'rejected' => (int) ($byStatus[Appointment::STATUS_REJECTED] ?? 0),
            'cancelled' => (int) ($byStatus[Appointment::STATUS_CANCELLED] ?? 0),
            'active_capacity' => $capacity,
            'occupied_slots' => $active,
            'utilization_rate' => $capacity > 0 ? round($active * 100 / $capacity, 1) : null,
            'average_attempt' => $attempts?->average_attempt !== null ? round((float) $attempts->average_attempt, 2) : null,
            'max_attempt' => (int) ($attempts->max_attempt ?? 0),
            'next_30_days_active' => $future,
            'timeline' => $timeline->map(fn ($row) => [
                'date' => (string) $row->date,
                'status' => (string) $row->status,
                'count' => (int) $row->total,
            ])->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function workflow(AnalyticsFilter $filter): array
    {
        $base = $this->applications($filter);
        $total = (clone $base)->count();
        $submitted = (clone $base)->whereNotNull('ca.submitted_at')->count();
        $staffReviewed = (clone $base)->whereNotNull('ca.decided_by_staff_user_id')->count();
        $adminReviewed = (clone $base)->whereNotNull('ca.decided_by_admin_user_id')->count();
        $withAppointment = (clone $base)->whereExists(fn ($query) => $query
            ->selectRaw('1')->from('appointments as stage_a')->whereColumn('stage_a.credit_application_id', 'ca.id'))->count();

        $stages = [
            ['stage' => 'created', 'count' => $total],
            ['stage' => 'submitted', 'count' => $submitted],
            ['stage' => 'staff_reviewed', 'count' => $staffReviewed],
            ['stage' => 'admin_reviewed', 'count' => $adminReviewed],
            ['stage' => 'appointment', 'count' => $withAppointment],
        ];

        return [
            'stages' => array_map(fn (array $stage) => array_merge($stage, [
                'completion_rate' => $total > 0 ? round($stage['count'] * 100 / $total, 1) : null,
            ]), $stages),
            'average_hours' => $this->stageDurations($filter),
        ];
    }

    /** @return array<string,mixed> */
    private function credit(AnalyticsFilter $filter): array
    {
        $byCurrency = $this->applications($filter)
            ->join('credit_requests as cr', 'cr.credit_application_id', '=', 'ca.id')
            ->selectRaw("COALESCE(cr.code_devise, 'N/A') as currency, COUNT(*) as applications")
            ->selectRaw('SUM(COALESCE(cr.montant_global_sollicite, 0)) as requested_total')
            ->selectRaw('AVG(cr.montant_global_sollicite) as requested_average')
            ->groupBy('cr.code_devise')
            ->get();

        $composition = $this->applications($filter)
            ->join('credit_requests as cr', 'cr.credit_application_id', '=', 'ca.id')
            ->selectRaw('SUM(COALESCE(cr.montant_eqp, 0)) as eqp')
            ->selectRaw('SUM(COALESCE(cr.montant_fdr, 0)) as fdr')
            ->selectRaw('SUM(COALESCE(cr.montant_amg, 0)) as amg')
            ->selectRaw('SUM(COALESCE(cr.montant_chp, 0)) as chp')
            ->first();

        $types = $this->dimensionBreakdown(
            $filter,
            'credit_requests',
            'cr',
            'type_demande',
            'credit_requests_type_application_index',
            20,
        );

        $eprDocuments = $this->applications($filter)
            ->join('documents as d', 'd.credit_application_id', '=', 'ca.id')
            ->where('d.document_type', 'epr')
            ->count();

        return [
            'by_currency' => $byCurrency->map(fn ($row) => [
                'currency' => (string) $row->currency,
                'applications' => (int) $row->applications,
                'requested_total' => round((float) $row->requested_total, 3),
                'requested_average' => $row->requested_average !== null ? round((float) $row->requested_average, 3) : null,
            ])->all(),
            'financing_composition' => [
                'EQP' => round((float) ($composition->eqp ?? 0), 3),
                'FDR' => round((float) ($composition->fdr ?? 0), 3),
                'AMG' => round((float) ($composition->amg ?? 0), 3),
                'CHP' => round((float) ($composition->chp ?? 0), 3),
            ],
            'epr_document_count' => $eprDocuments,
            'request_types' => $types,
        ];
    }

    /** @return array<string,list<array{label:string,count:int}>> */
    private function geography(AnalyticsFilter $filter): array
    {
        return [
            'governorates' => $this->dimensionBreakdown($filter, 'projects', 'p', 'ville', 'projects_ville_application_index'),
            'delegations' => $this->dimensionBreakdown($filter, 'projects', 'p', 'delegation', 'projects_delegation_application_index'),
            'project_types' => $this->dimensionBreakdown($filter, 'projects', 'p', 'type_projet', 'projects_type_application_index'),
            'activities' => $this->dimensionBreakdown($filter, 'projects', 'p', 'activite', 'projects_activity_application_index'),
        ];
    }

    /** @return list<array{label:string,count:int}> */
    private function dimensionBreakdown(
        AnalyticsFilter $filter,
        string $table,
        string $alias,
        string $column,
        string $index,
        int $limit = 30,
    ): array {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $this->applications($filter)
                ->join("{$table} as {$alias}", "{$alias}.credit_application_id", '=', 'ca.id')
                ->selectRaw("COALESCE({$alias}.{$column}, 'Non renseigné') as label, COUNT(*) as count")
                ->groupBy("{$alias}.{$column}")
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->count])
                ->all();
        }

        $conditions = ['ca.deleted_at IS NULL', 'ca.created_at >= ?', 'ca.created_at <= ?'];
        $bindings = [$filter->from->toDateTimeString(), $filter->to->toDateTimeString()];
        if ($filter->branchId !== null) {
            $conditions[] = 'ca.branch_id = ?';
            $bindings[] = $filter->branchId;
        }
        if ($filter->status !== null) {
            $conditions[] = 'ca.status = ?';
            $bindings[] = $filter->status;
        }

        // MariaDB otherwise performs tens of thousands of random lookups into the
        // wide dimension table. These measured covering indexes make the dimension
        // scan sequential, then validate the bounded application cohort by primary key.
        $sql = "SELECT COALESCE({$alias}.{$column}, 'Non renseigné') AS label, COUNT(*) AS count
                FROM {$table} AS {$alias} FORCE INDEX ({$index})
                STRAIGHT_JOIN credit_applications AS ca ON ca.id = {$alias}.credit_application_id
                WHERE ".implode(' AND ', $conditions)."
                GROUP BY {$alias}.{$column} ORDER BY count DESC LIMIT {$limit}";

        return collect(DB::select($sql, $bindings))
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->count])
            ->all();
    }

    private function decisionSubquery(): Builder
    {
        $directActions = DB::table('audit_logs')
            ->whereNotNull('credit_application_id')
            ->whereIn('action', self::DECISION_ACTIONS)
            ->select(['credit_application_id', 'created_at']);
        $statusTransitions = DB::table('audit_logs')
            ->whereNotNull('credit_application_id')
            ->where('action', 'credit_application.status_changed')
            ->whereIn('analytics_status', self::DECISION_STATUSES)
            ->select(['credit_application_id', 'created_at']);

        return DB::query()->fromSub($directActions->unionAll($statusTransitions), 'decision_events')
            ->selectRaw('credit_application_id, MIN(created_at) as decided_at')
            ->groupBy('credit_application_id');
    }

    private function averageDecisionHours(AnalyticsFilter $filter): ?float
    {
        $query = $this->applications($filter)
            ->joinSub($this->decisionSubquery(), 'decision', 'decision.credit_application_id', '=', 'ca.id')
            ->whereNotNull('ca.submitted_at');

        $value = $query->selectRaw($this->averageHoursExpression('ca.submitted_at', 'decision.decided_at').' as hours')->value('hours');

        return $value !== null ? round((float) $value, 1) : null;
    }

    /** @return Collection<int,float> */
    private function averageDecisionHoursByBranch(AnalyticsFilter $filter): Collection
    {
        return $this->applications($filter)
            ->joinSub($this->decisionSubquery(), 'decision', 'decision.credit_application_id', '=', 'ca.id')
            ->whereNotNull('ca.submitted_at')
            ->whereNotNull('ca.branch_id')
            ->selectRaw('ca.branch_id, '.$this->averageHoursExpression('ca.submitted_at', 'decision.decided_at').' as hours')
            ->groupBy('ca.branch_id')
            ->pluck('hours', 'branch_id');
    }

    /** @return array{creation_to_submission:?float,submission_to_first_decision:?float,decision_to_first_appointment:?float} */
    private function stageDurations(AnalyticsFilter $filter): array
    {
        $creationToSubmission = $this->applications($filter)
            ->whereNotNull('ca.submitted_at')
            ->selectRaw($this->averageHoursExpression('ca.created_at', 'ca.submitted_at').' as hours')
            ->value('hours');

        $decisionBase = $this->applications($filter)
            ->joinSub($this->decisionSubquery(), 'decision', 'decision.credit_application_id', '=', 'ca.id');
        $submissionToDecision = (clone $decisionBase)
            ->whereNotNull('ca.submitted_at')
            ->selectRaw($this->averageHoursExpression('ca.submitted_at', 'decision.decided_at').' as hours')
            ->value('hours');

        $firstAppointment = DB::table('appointments')
            ->selectRaw('credit_application_id, MIN(created_at) as appointment_at')
            ->groupBy('credit_application_id');
        $decisionToAppointment = (clone $decisionBase)
            ->joinSub($firstAppointment, 'first_appointment', 'first_appointment.credit_application_id', '=', 'ca.id')
            ->selectRaw($this->averageHoursExpression('decision.decided_at', 'first_appointment.appointment_at').' as hours')
            ->value('hours');

        $round = fn ($value): ?float => $value !== null ? round((float) $value, 1) : null;

        return [
            'creation_to_submission' => $round($creationToSubmission),
            'submission_to_first_decision' => $round($submissionToDecision),
            'decision_to_first_appointment' => $round($decisionToAppointment),
        ];
    }

    private function averageHoursExpression(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "AVG((julianday({$to}) - julianday({$from})) * 24.0)"
            : "AVG(TIMESTAMPDIFF(SECOND, {$from}, {$to}) / 3600.0)";
    }

    private function workingDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = 0;
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }
}
