<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OperationalHeartbeatService
{
    public function touchWorker(): void
    {
        Cache::forever((string) config('operations.worker.heartbeat_key'), now()->timestamp);
    }

    public function touchScheduler(): void
    {
        Cache::forever((string) config('operations.scheduler.heartbeat_key'), now()->timestamp);
    }
}
