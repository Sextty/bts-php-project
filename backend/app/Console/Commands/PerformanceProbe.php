<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PerformanceProbe extends Command
{
    protected $signature = 'bts:performance-probe {--iterations=30 : Repetitions per read query} {--json : Emit machine-readable JSON}';

    protected $description = 'Run read-only BTS Phase 3 query latency and EXPLAIN probes';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Performance probes are disabled in production.');

            return self::FAILURE;
        }

        $iterations = max(1, min(500, (int) $this->option('iterations')));
        $branchId = (int) (DB::table('branches')->value('id') ?? 0);
        $userId = (int) (DB::table('credit_applications')->value('user_id') ?? 0);
        $probes = [
            'customer_applications' => fn () => DB::table('credit_applications')
                ->where('user_id', $userId)->orderByDesc('id')->limit(25)->get(['id', 'status', 'created_at']),
            'staff_application_queue' => fn () => DB::table('credit_applications')
                ->where('branch_id', $branchId)->whereIn('status', ['submitted', 'staff_approved'])
                ->orderByDesc('created_at')->orderByDesc('id')->limit(25)->get(['id', 'status', 'created_at']),
            'audit_activity' => fn () => DB::table('audit_logs')
                ->where('action', 'credit_application.created')->where('created_at', '>=', now()->subYears(2))
                ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get(['id', 'created_at']),
        ];

        $result = [];
        foreach ($probes as $name => $probe) {
            $samples = [];
            for ($i = 0; $i < $iterations; $i++) {
                $started = hrtime(true);
                $probe();
                $samples[] = (hrtime(true) - $started) / 1_000_000;
            }
            sort($samples);
            $result[$name] = [
                'iterations' => $iterations,
                'average_ms' => round(array_sum($samples) / count($samples), 3),
                'p95_ms' => round($samples[(int) floor((count($samples) - 1) * 0.95)], 3),
                'max_ms' => round(max($samples), 3),
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Probe', 'Iterations', 'Average ms', 'P95 ms', 'Max ms'], collect($result)->map(
                fn (array $row, string $name) => [$name, $row['iterations'], $row['average_ms'], $row['p95_ms'], $row['max_ms']],
            )->values()->all());
        }

        return self::SUCCESS;
    }
}
