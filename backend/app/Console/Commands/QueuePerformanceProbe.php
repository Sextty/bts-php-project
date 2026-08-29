<?php

namespace App\Console\Commands;

use App\Jobs\MeasureQueueThroughputJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

class QueuePerformanceProbe extends Command
{
    protected $signature = 'bts:queue-probe {--jobs=1000 : Synthetic no-op jobs} {--json : Emit machine-readable JSON}';

    protected $description = 'Measure database queue enqueue and single-worker drain throughput on a dedicated test database';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (app()->environment('production') || ! preg_match('/(?:test|phase3|bench)/i', $database)) {
            $this->error('Queue probe requires a dedicated test/phase3/benchmark database and never runs in production.');

            return self::FAILURE;
        }

        if (DB::table('jobs')->where('queue', 'phase3-probe')->exists()) {
            $this->error('The phase3-probe queue is not empty; drain it before measuring.');

            return self::FAILURE;
        }

        $count = max(1, min(20_000, (int) $this->option('jobs')));
        $enqueueStart = hrtime(true);
        for ($i = 1; $i <= $count; $i++) {
            MeasureQueueThroughputJob::dispatch($i)->onConnection('database')->onQueue('phase3-probe');
        }
        $enqueueSeconds = (hrtime(true) - $enqueueStart) / 1_000_000_000;

        $drainStart = hrtime(true);
        $workerOutput = new BufferedOutput;
        $exit = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'phase3-probe',
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--sleep' => 0,
            '--timeout' => 10,
        ], $workerOutput);
        $drainSeconds = (hrtime(true) - $drainStart) / 1_000_000_000;

        $result = [
            'jobs' => $count,
            'enqueue_seconds' => round($enqueueSeconds, 3),
            'enqueue_jobs_per_second' => round($count / max($enqueueSeconds, 0.001), 1),
            'single_worker_drain_seconds' => round($drainSeconds, 3),
            'single_worker_jobs_per_second' => round($count / max($drainSeconds, 0.001), 1),
            'remaining_probe_jobs' => DB::table('jobs')->where('queue', 'phase3-probe')->count(),
            'worker_exit_code' => $exit,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Metric', 'Value'], collect($result)->map(fn ($value, $key) => [$key, $value])->values()->all());
        }

        return $exit === self::SUCCESS && $result['remaining_probe_jobs'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
