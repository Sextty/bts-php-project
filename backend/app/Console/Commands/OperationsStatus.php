<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

class OperationsStatus extends Command
{
    protected $signature = 'operations:status {--json : Emit a machine-readable readiness report}';

    protected $description = 'Show dependency readiness for operators without exposing configuration or secrets';

    public function handle(HealthCheckService $health): int
    {
        $report = $health->check();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Component', 'Status', 'Detail'], collect($report['checks'])
                ->map(fn (array $check, string $name) => [$name, $check['ok'] ? 'OK' : 'DEGRADED', $check['detail']])
                ->values()
                ->all());
        }

        return $report['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
