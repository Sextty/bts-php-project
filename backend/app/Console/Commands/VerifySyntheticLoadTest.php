<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CreditApplicationStateMachine;
use App\Services\LoadTesting\LoadTestSafetyGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class VerifySyntheticLoadTest extends Command
{
    protected $signature = 'bts:verify-load-test
                            {--manifest= : Credential manifest created by bts:prepare-load-test}
                            {--scenario=login}
                            {--accounts=0}
                            {--applications-per-account=1}
                            {--json=}';

    protected $description = 'Verify business correctness after an isolated synthetic API load campaign';

    public function handle(
        LoadTestSafetyGate $safety,
        AuditLogService $audit,
        CreditApplicationStateMachine $stateMachine,
    ): int {
        try {
            $marker = $safety->assertActive();
            $manifest = $this->readManifest((string) $this->option('manifest'));
            if (($manifest['campaign_id'] ?? null) !== $marker['campaign_id']) {
                throw new \RuntimeException('Manifest campaign does not match the active isolated database.');
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $userIds = collect($manifest['customers'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $applicationIds = CreditApplication::query()->whereIn('user_id', $userIds)->pluck('id')->all();
        $checks = [];

        $this->recordWorkflowCompleteness($checks, $applicationIds);

        $this->record($checks, 'customer_ownership', CreditApplication::query()->whereIn('id', $applicationIds)->whereNotIn('user_id', $userIds)->count() === 0);
        $this->record($checks, 'valid_statuses', CreditApplication::query()->whereIn('id', $applicationIds)->whereNotIn('status', CreditApplication::STATUS_ORDER)->count() === 0);
        $this->record($checks, 'unique_customer_email', $this->duplicateCount('users', 'email', $userIds) === 0);
        $this->record($checks, 'unique_customer_phone', $this->duplicateCount('users', 'phone', $userIds) === 0);
        $this->record($checks, 'unique_application_number', $this->duplicateChildNaturalKey('credit_requests', 'n_demande', $applicationIds) === 0);
        $this->record($checks, 'unique_client_number', $this->duplicateChildNaturalKey('clients', 'code_client', $applicationIds) === 0);
        $this->record($checks, 'unique_project_number', $this->duplicateChildNaturalKey('projects', 'code_projet', $applicationIds) === 0);
        $this->record($checks, 'complete_advanced_applications', $this->partialApplicationCount($applicationIds) === 0);
        $this->record($checks, 'routed_submissions', CreditApplication::query()->whereIn('id', $applicationIds)->whereIn('status', array_slice(CreditApplication::STATUS_ORDER, 8))->whereNull('branch_id')->count() === 0);
        $this->record($checks, 'unique_appointment_attempts', $this->duplicateAppointmentAttemptCount($applicationIds) === 0);
        $this->record($checks, 'no_double_booked_active_slots', $this->doubleBookedSlotCount($applicationIds) === 0);
        $this->record($checks, 'auto_appointments_after_today', $this->invalidAutomaticAppointmentCount($applicationIds) === 0);
        $this->record($checks, 'branch_daily_capacity', $this->overCapacityCount($applicationIds) === 0);
        $this->record($checks, 'notification_deduplication', $this->notificationDuplicateCount($userIds) === 0);
        $this->record($checks, 'branch_isolation', $this->crossBranchDecisionCount($applicationIds) === 0);
        $this->record($checks, 'valid_audited_transitions', $this->invalidTransitionCount($applicationIds, $stateMachine) === 0);

        $auditResult = $audit->verifyIntegrity();
        $this->record($checks, 'audit_integrity', (bool) $auditResult['valid'], $auditResult['errors'] ?? []);

        $result = [
            'marker' => LoadTestSafetyGate::MARKER,
            'campaign_id' => $manifest['campaign_id'],
            'applications_checked' => count($applicationIds),
            'passed' => collect($checks)->every(fn ($check) => $check['passed']),
            'checks' => $checks,
            'verified_at' => now()->toIso8601String(),
        ];

        if ($path = $this->option('json')) {
            File::put((string) $path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }

    private function readManifest(string $path): array
    {
        if ($path === '' || ! File::isFile($path)) {
            throw new \RuntimeException('A readable load-test manifest is required.');
        }
        $manifest = json_decode((string) File::get($path), true);
        if (! is_array($manifest) || ($manifest['marker'] ?? null) !== LoadTestSafetyGate::MARKER) {
            throw new \RuntimeException('Invalid synthetic load-test manifest.');
        }

        return $manifest;
    }

    private function record(array &$checks, string $name, bool $passed, array $details = []): void
    {
        $checks[$name] = ['passed' => $passed, 'details' => $details];
    }

    private function recordWorkflowCompleteness(array &$checks, array $applicationIds): void
    {
        $scenario = (string) $this->option('scenario');
        if (in_array($scenario, ['login', 'read'], true)) {
            return;
        }

        $expectedCount = max(0, (int) $this->option('accounts')) * max(1, (int) $this->option('applications-per-account'));
        $expectedStatuses = match ($scenario) {
            'application' => [CreditApplication::STATUS_SUBMITTED],
            'staff_review' => [CreditApplication::STATUS_STAFF_APPROVED],
            'admin_review' => [CreditApplication::STATUS_APPOINTMENT_PROPOSED],
            'appointment', 'chat', 'full' => [CreditApplication::STATUS_APPOINTMENT_CONFIRMED],
            default => [],
        };
        $unexpected = $expectedStatuses === [] ? 0 : CreditApplication::query()
            ->whereIn('id', $applicationIds)
            ->whereNotIn('status', $expectedStatuses)
            ->count();

        $this->record($checks, 'expected_workflow_record_count', count($applicationIds) === $expectedCount, [
            'expected' => $expectedCount,
            'actual' => count($applicationIds),
        ]);
        $this->record($checks, 'no_unfinished_workflows', $unexpected === 0, [
            'expected_statuses' => $expectedStatuses,
            'unexpected' => $unexpected,
        ]);
    }

    private function duplicateCount(string $table, string $column, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table($table)->select($column)->whereIn('id', $ids)->whereNotNull($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->get()->count();
    }

    private function duplicateChildNaturalKey(string $table, string $column, array $applicationIds): int
    {
        if ($applicationIds === []) {
            return 0;
        }

        return DB::table($table)->select($column)->whereIn('credit_application_id', $applicationIds)->whereNotNull($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->get()->count();
    }

    private function partialApplicationCount(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $advanced = array_slice(CreditApplication::STATUS_ORDER, 3);

        return CreditApplication::query()->whereIn('id', $ids)->whereIn('status', $advanced)
            ->where(fn ($query) => $query->whereDoesntHave('client')->orWhereDoesntHave('creditRequest')->orWhereDoesntHave('project'))
            ->count();
    }

    private function duplicateAppointmentAttemptCount(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('appointments')->select('credit_application_id', 'attempt_number')->whereIn('credit_application_id', $ids)
            ->groupBy('credit_application_id', 'attempt_number')->havingRaw('COUNT(*) > 1')->get()->count();
    }

    private function doubleBookedSlotCount(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('appointments')->select('branch_id', 'scheduled_date', 'scheduled_time')->whereIn('status', ['proposed', 'accepted'])
            ->groupBy('branch_id', 'scheduled_date', 'scheduled_time')->havingRaw('COUNT(*) > 1')->get()->count();
    }

    private function invalidAutomaticAppointmentCount(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('appointments')->whereIn('credit_application_id', $ids)
            ->whereDate('scheduled_date', '<=', now()->toDateString())->count();
    }

    private function overCapacityCount(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('appointments as a')->select('a.branch_id', 'a.scheduled_date', 'b.daily_capacity')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->whereIn('a.status', ['proposed', 'accepted'])
            ->groupBy('a.branch_id', 'a.scheduled_date', 'b.daily_capacity')
            ->havingRaw('COUNT(*) > b.daily_capacity')->get()->count();
    }

    private function notificationDuplicateCount(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return DB::table('app_notifications')->select('notifiable_type', 'notifiable_id', 'type', 'dedupe_key')->where('notifiable_type', User::class)->whereIn('notifiable_id', $userIds)
            ->whereNotNull('dedupe_key')->groupBy('notifiable_type', 'notifiable_id', 'type', 'dedupe_key')
            ->havingRaw('COUNT(*) > 1')->get()->count();
    }

    private function crossBranchDecisionCount(array $applicationIds): int
    {
        if ($applicationIds === []) {
            return 0;
        }

        return DB::table('audit_logs as l')->join('staff_users as s', 's.id', '=', 'l.staff_user_id')
            ->join('credit_applications as a', 'a.id', '=', 'l.credit_application_id')
            ->whereIn('a.id', $applicationIds)->whereNotNull('s.branch_id')->whereColumn('s.branch_id', '!=', 'a.branch_id')
            ->whereIn('s.role', ['staff', 'credit_officer', 'senior_staff', 'branch_manager'])->count();
    }

    private function invalidTransitionCount(array $applicationIds, CreditApplicationStateMachine $stateMachine): int
    {
        if ($applicationIds === []) {
            return 0;
        }
        $invalid = 0;
        $logs = AuditLog::query()->whereIn('credit_application_id', $applicationIds)->where('action', 'credit_application.status_changed')->get();
        foreach ($logs as $log) {
            $actor = $log->staff_user_id ? StaffUser::find($log->staff_user_id) : User::find($log->user_id);
            $application = CreditApplication::find($log->credit_application_id);
            $from = $log->previous_state['status'] ?? null;
            $to = $log->new_state['status'] ?? null;
            if (! $actor || ! $application || ! is_string($from) || ! is_string($to)) {
                $invalid++;

                continue;
            }
            $application->status = $from;
            if (! $stateMachine->canTransition($application, $to, $actor)) {
                $invalid++;
            }
        }

        return $invalid;
    }
}
