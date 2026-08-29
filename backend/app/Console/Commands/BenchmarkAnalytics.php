<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsDataQualityService;
use App\Services\Analytics\AnalyticsFilter;
use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BenchmarkAnalytics extends Command
{
    protected $signature = 'analytics:benchmark
                            {--from= : Inclusive YYYY-MM-DD start date}
                            {--to= : Inclusive YYYY-MM-DD end date}
                            {--branch= : Optional branch ID}
                            {--json= : Optional JSON output path}';

    protected $description = 'Measure representative read-only BTS analytics queries and plans';

    public function handle(AnalyticsService $analytics, AnalyticsDataQualityService $quality): int
    {
        $latest = DB::table('credit_applications')->max('created_at');
        $to = CarbonImmutable::parse($this->option('to') ?: ($latest ?: now()))->endOfDay();
        $from = CarbonImmutable::parse($this->option('from') ?: $to->subDays(364))->startOfDay();
        if ($from->greaterThan($to)) {
            $this->error('The start date must not be after the end date.');

            return self::INVALID;
        }

        $filter = new AnalyticsFilter($from, $to, $this->option('branch') !== null ? (int) $this->option('branch') : null, null);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $memoryBefore = memory_get_usage(true);
        $started = hrtime(true);
        $snapshot = $analytics->snapshot($filter);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $result = [
            'marker' => 'SYNTHETIC_ANALYTICS_BENCHMARK',
            'database' => DB::getDatabaseName(),
            'driver' => DB::connection()->getDriverName(),
            'filters' => $filter->metadata(),
            'dataset' => [
                'customers' => DB::table('users')->count(),
                'applications' => DB::table('credit_applications')->whereNull('deleted_at')->count(),
                'appointments' => DB::table('appointments')->count(),
                'audit_logs' => DB::table('audit_logs')->count(),
            ],
            'measurement' => [
                'snapshot_ms' => round($elapsedMs, 3),
                'query_count' => count($queryLog),
                'database_query_ms' => round((float) collect($queryLog)->sum('time'), 3),
                'slowest_queries' => collect($queryLog)
                    ->sortByDesc('time')
                    ->take(10)
                    ->values()
                    ->map(fn (array $query) => [
                        'time_ms' => round((float) $query['time'], 3),
                        'sql' => preg_replace('/\s+/', ' ', (string) $query['query']),
                    ])->all(),
                'memory_delta_bytes' => max(0, memory_get_usage(true) - $memoryBefore),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'response_bytes' => strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)),
            ],
            'plans' => $this->plans($from, $to),
            'data_quality' => $quality->verify(),
            'measured_at' => now()->toIso8601String(),
        ];

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($path = $this->option('json')) {
            $directory = dirname((string) $path);
            if (! is_dir($directory)) {
                mkdir($directory, 0750, true);
            }
            file_put_contents((string) $path, $json.PHP_EOL, LOCK_EX);
        }
        $this->line($json);

        return $result['data_quality']['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function plans(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $queries = [
            'application_statuses' => 'SELECT status, COUNT(*) FROM credit_applications WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ? GROUP BY status',
            'branch_workload' => 'SELECT branch_id, status, COUNT(*) FROM credit_applications WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ? GROUP BY branch_id, status',
            'appointment_utilization' => 'SELECT branch_id, status, COUNT(*) FROM appointments WHERE scheduled_date >= ? AND scheduled_date <= ? GROUP BY branch_id, status',
            'workflow_decisions' => "SELECT credit_application_id, MIN(created_at) FROM audit_logs WHERE action = 'credit_application.status_changed' AND analytics_status IN ('STAFF_APPROVED','STAFF_REJECTED','APPROVED','REJECTED') AND created_at >= ? AND created_at <= ? GROUP BY credit_application_id",
            'project_geography' => 'SELECT STRAIGHT_JOIN p.ville, COUNT(*) FROM projects p FORCE INDEX (projects_ville_application_index) INNER JOIN credit_applications ca ON ca.id = p.credit_application_id WHERE ca.deleted_at IS NULL AND ca.created_at >= ? AND ca.created_at <= ? GROUP BY p.ville',
        ];

        $plans = [];
        foreach ($queries as $name => $sql) {
            $parameters = str_contains($sql, 'scheduled_date')
                ? [$from->toDateString(), $to->toDateString()]
                : [$from->toDateTimeString(), $to->toDateTimeString()];
            $prefix = DB::connection()->getDriverName() === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
            $plans[$name] = array_map(fn ($row) => (array) $row, DB::select($prefix.$sql, $parameters));
        }

        return $plans;
    }
}
