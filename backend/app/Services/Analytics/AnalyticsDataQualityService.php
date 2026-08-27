<?php

namespace App\Services\Analytics;

use App\Models\CreditApplication;
use Illuminate\Support\Facades\DB;

final class AnalyticsDataQualityService
{
    /** @return array{passed:bool,total_violations:int,checks:array<string,array{passed:bool,violations:int}>} */
    public function verify(): array
    {
        $checks = [
            'valid_application_statuses' => DB::table('credit_applications')
                ->whereNull('deleted_at')->whereNotIn('status', CreditApplication::STATUS_ORDER)->count(),
            'submission_not_before_creation' => DB::table('credit_applications')
                ->whereNull('deleted_at')->whereNotNull('submitted_at')->whereColumn('submitted_at', '<', 'created_at')->count(),
            'submitted_applications_have_branch' => DB::table('credit_applications')
                ->whereNull('deleted_at')->whereNotNull('submitted_at')->whereNull('branch_id')->count(),
            'appointments_have_application' => DB::table('appointments as a')
                ->leftJoin('credit_applications as ca', 'ca.id', '=', 'a.credit_application_id')->whereNull('ca.id')->count(),
            'appointments_match_application_branch' => DB::table('appointments as a')
                ->join('credit_applications as ca', 'ca.id', '=', 'a.credit_application_id')
                ->whereNotNull('ca.branch_id')->whereColumn('a.branch_id', '!=', 'ca.branch_id')->count(),
            'automatic_appointments_start_after_creation_day' => DB::table('appointments')
                ->where('is_auto_scheduled_future', true)
                ->whereRaw('scheduled_date <= DATE(created_at)')->count(),
            'unique_request_numbers' => $this->duplicateCount('credit_requests', 'n_demande'),
            'unique_client_numbers' => $this->duplicateCount('clients', 'code_client'),
            'unique_project_numbers' => $this->duplicateCount('projects', 'code_projet'),
            'financing_breakdown_matches_requested_total' => DB::table('credit_requests')
                ->whereNotNull('montant_global_sollicite')
                ->whereRaw('ABS(COALESCE(montant_eqp, 0) + COALESCE(montant_fdr, 0) + COALESCE(montant_amg, 0) + COALESCE(montant_chp, 0) - montant_global_sollicite) > 0.001')
                ->count(),
        ];

        $presented = collect($checks)->map(fn (int $violations) => [
            'passed' => $violations === 0,
            'violations' => $violations,
        ])->all();
        $total = array_sum($checks);

        return ['passed' => $total === 0, 'total_violations' => $total, 'checks' => $presented];
    }

    private function duplicateCount(string $table, string $column): int
    {
        return DB::query()->fromSub(
            DB::table($table)
                ->select($column)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1'),
            'duplicates',
        )->count();
    }
}
