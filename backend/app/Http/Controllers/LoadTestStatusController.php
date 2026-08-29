<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\HealthCheckService;
use App\Services\LoadTesting\LoadTestSafetyGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class LoadTestStatusController extends Controller
{
    public function __invoke(LoadTestSafetyGate $safety, HealthCheckService $health): JsonResponse
    {
        abort_unless($safety->isActive(), 404);
        $marker = $safety->assertActive();

        return ApiResponse::ok([
            'marker' => LoadTestSafetyGate::MARKER,
            'campaign_id' => $marker['campaign_id'],
            'database' => $marker['database'],
            'environment' => app()->environment(),
            'synthetic_accounts' => User::query()
                ->where('email', 'like', 'load-%@synthetic.bts.invalid')
                ->count(),
            'health' => $health->check(),
            'database_metrics' => $this->databaseMetrics(),
            'process' => ['memory_bytes' => memory_get_usage(true)],
        ]);
    }

    private function databaseMetrics(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return ['provider' => DB::connection()->getDriverName()];
        }

        $wanted = [
            'Threads_connected',
            'Max_used_connections',
            'Innodb_deadlocks',
            'Innodb_row_lock_current_waits',
            'Innodb_row_lock_time',
            'Innodb_row_lock_waits',
        ];

        $rows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('".implode("','", $wanted)."')");
        $metrics = [];
        foreach ($rows as $row) {
            $metrics[$row->Variable_name] = is_numeric($row->Value) ? (int) $row->Value : $row->Value;
        }

        return $metrics;
    }
}
