<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Readiness probe for the production dependency surface. Returns one row per
 * dependency with a boolean `ok` and a short `detail` — never exception messages,
 * never credentials, never internal state that could help an attacker.
 *
 *   - database : SELECT 1 against the configured connection.
 *   - cache    : write/read/delete probe through the configured cache store
 *                (database, redis, file, ... — whatever CACHE_STORE says).
 *   - redis    : explicit PING only when the cache or queue actually uses Redis
 *                (REDIS_CLIENT=phpredis). Reported as 'unused' otherwise — it is
 *                not a failure for a backend to be unused.
 *   - storage  : write/read/delete probe on the documents disk (local or S3).
 *                A bucket that is simply missing is a real failure.
 *   - queue    : for the database driver the DB probe already covers it; for the
 *                redis driver the Redis probe does. 'sync'/'null' are always fine.
 *   - reverb   : best-effort TCP connect to the broadcasting server. Skipped
 *                (not a failure) when Reverb is not configured at all.
 *
 * A component is reported degraded only when the application actually depends on
 * it. The endpoint returns 200 while every dependency is ok, 503 otherwise —
 * load balancers use that binary signal, and the body carries the detail.
 */
class HealthCheckService
{
    public function check(): array
    {
        $database = $this->checkDatabase();
        $cache = $this->checkCache();
        $redis = $this->checkRedis();
        $storage = $this->checkStorage();
        $disk = $this->checkDiskSpace();
        $queue = $this->checkQueue($database, $redis);
        $worker = $this->checkHeartbeat('worker');
        $scheduler = $this->checkHeartbeat('scheduler');
        $failedJobs = $this->checkFailedJobs($database);
        $outbox = $this->checkAsyncOutbox($database);
        $reverb = $this->checkReverb();

        $checks = [
            'database' => $database,
            'cache' => $cache,
            'redis' => $redis,
            'storage' => $storage,
            'disk_space' => $disk,
            'queue' => $queue,
            'queue_worker' => $worker,
            'scheduler' => $scheduler,
            'failed_jobs' => $failedJobs,
            'async_outbox' => $outbox,
            'reverb' => $reverb,
        ];

        $ok = collect($checks)->every(fn (array $check) => $check['ok']);

        return [
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'detail' => 'connected'];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkCache(): array
    {
        $key = 'health:probe:'.Str::uuid()->toString();

        try {
            Cache::put($key, 'ok', 1);
            $read = Cache::get($key);
            Cache::forget($key);

            return $read === 'ok'
                ? ['ok' => true, 'detail' => 'ok']
                : ['ok' => false, 'detail' => 'write_mismatch'];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkRedis(): array
    {
        $cacheUsesRedis = config('cache.default') === 'redis';
        $queueUsesRedis = config('queue.default') === 'redis';
        $broadcastUsesRedis = config('broadcasting.default') === 'redis';

        if (! $cacheUsesRedis && ! $queueUsesRedis && ! $broadcastUsesRedis) {
            return ['ok' => true, 'detail' => 'unused'];
        }

        try {
            Redis::connection()->ping();

            return ['ok' => true, 'detail' => 'ping_ok'];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkStorage(): array
    {
        $disk = config('filesystems.disks.documents.driver', 'local');
        $name = 'health:probe-'.Str::uuid()->toString().'.tmp';

        try {
            $storage = Storage::disk('documents');
            $storage->put($name, 'ok');
            $read = $storage->get($name);
            $storage->delete($name);

            return ($read === 'ok')
                ? ['ok' => true, 'detail' => "writable ({$disk})"]
                : ['ok' => false, 'detail' => 'write_mismatch'];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }

    /**
     * `disk_free_space` is safe and inexpensive for local storage. Never expose an internal
     * path or the exact capacity publicly; operators only need a stable sufficient/low signal.
     */
    private function checkDiskSpace(): array
    {
        if (config('filesystems.disks.documents.driver', 'local') !== 'local') {
            return ['ok' => true, 'detail' => 'provider_managed'];
        }

        try {
            $root = (string) config('filesystems.disks.documents.root', storage_path('app/documents'));
            $freeBytes = @disk_free_space($root);
            if ($freeBytes === false) {
                return ['ok' => false, 'detail' => 'unavailable'];
            }

            $ok = $freeBytes >= (int) config('operations.storage.min_free_bytes', 1_073_741_824);

            return ['ok' => $ok, 'detail' => $ok ? 'sufficient' : 'low'];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'unavailable'];
        }
    }

    /**
     * The queue's backing store is either the database or Redis — whichever it is,
     * the dedicated probe above already covered it. A 'database' queue with a dead
     * DB is a failed database check, not a separate failure.
     *
     * @param  array{ok: bool, detail: string}  $database
     * @param  array{ok: bool, detail: string}  $redis
     * @return array{ok: bool, detail: string}
     */
    private function checkQueue(array $database, array $redis): array
    {
        return match (config('queue.default')) {
            'sync', 'null' => ['ok' => true, 'detail' => 'sync (no worker needed)'],
            'redis' => $redis['ok']
                ? ['ok' => true, 'detail' => 'redis backing store ok']
                : ['ok' => false, 'detail' => 'redis unreachable'],
            'database' => $this->checkDatabaseQueue($database),
            default => $database['ok']
                ? ['ok' => true, 'detail' => 'backing store ok']
                : ['ok' => false, 'detail' => 'backing store unreachable'],
        };
    }

    /** @param array{ok: bool, detail: string} $database */
    private function checkDatabaseQueue(array $database): array
    {
        if (! $database['ok']) {
            return ['ok' => false, 'detail' => 'database unreachable'];
        }

        try {
            $table = config('queue.connections.database.table', 'jobs');
            $now = now()->timestamp;
            $readyQuery = DB::table($table)->whereNull('reserved_at')->where('available_at', '<=', $now);
            $ready = (clone $readyQuery)->count();
            $delayed = DB::table($table)->whereNull('reserved_at')->where('available_at', '>', $now)->count();
            $reserved = DB::table($table)->whereNotNull('reserved_at')->count();
            $oldest = (clone $readyQuery)->min('created_at');
            $oldestSeconds = $oldest === null ? 0 : max(0, $now - (int) $oldest);
            $maxReady = (int) config('operations.queue.max_ready', 10_000);
            $maxAge = (int) config('operations.queue.max_ready_age_seconds', 300);
            $ok = $ready <= $maxReady && $oldestSeconds <= $maxAge;

            return [
                'ok' => $ok,
                'detail' => "database queue; ready={$ready}; delayed={$delayed}; reserved={$reserved}; oldest_ready_seconds={$oldestSeconds}",
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'queue table unreachable'];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkHeartbeat(string $component): array
    {
        $settings = (array) config("operations.{$component}", []);
        if (! ($settings['required'] ?? false)) {
            return ['ok' => true, 'detail' => 'not required'];
        }

        try {
            $timestamp = Cache::get((string) ($settings['heartbeat_key'] ?? ''));
            if (! is_numeric($timestamp)) {
                return ['ok' => false, 'detail' => 'heartbeat missing'];
            }

            $age = max(0, now()->timestamp - (int) $timestamp);
            $ok = $age <= (int) ($settings['max_age_seconds'] ?? 120);

            return ['ok' => $ok, 'detail' => $ok ? "heartbeat ok; age_seconds={$age}" : "heartbeat stale; age_seconds={$age}"];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'heartbeat unavailable'];
        }
    }

    /** @param array{ok: bool, detail: string} $database */
    private function checkFailedJobs(array $database): array
    {
        if (! $database['ok']) {
            return ['ok' => false, 'detail' => 'database unreachable'];
        }

        try {
            $count = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            $maximum = (int) config('operations.queue.max_failed', 0);

            return ['ok' => $count <= $maximum, 'detail' => "count={$count}; allowed={$maximum}"];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'failed-job store unreachable'];
        }
    }

    /** @param array{ok: bool, detail: string} $database */
    private function checkAsyncOutbox(array $database): array
    {
        if (! $database['ok']) {
            return ['ok' => false, 'detail' => 'database unreachable'];
        }

        try {
            $table = DB::table('async_outbox_events');
            $pending = (clone $table)->whereIn('status', ['pending', 'retrying'])->count();
            $processing = (clone $table)->where('status', 'processing')->count();
            $failed = (clone $table)->where('status', 'failed')->count();
            $oldest = (clone $table)->whereIn('status', ['pending', 'retrying'])->min('created_at');
            $averageDelay = (int) round((float) ((clone $table)->where('status', 'processed')->avg('queue_delay_ms') ?? 0));
            $averageRuntime = (int) round((float) ((clone $table)->where('status', 'processed')->avg('runtime_ms') ?? 0));
            $oldestSeconds = $oldest ? max(0, now()->diffInSeconds($oldest)) : 0;
            $maxPending = (int) config('operations.outbox.max_pending', 10_000);
            $maxAge = (int) config('operations.outbox.max_pending_age_seconds', 300);
            $maxFailed = (int) config('operations.outbox.max_failed', 0);
            $ok = $pending <= $maxPending && $oldestSeconds <= $maxAge && $failed <= $maxFailed;

            return [
                'ok' => $ok,
                'detail' => "pending={$pending}; processing={$processing}; failed={$failed}; oldest_pending_seconds={$oldestSeconds}; avg_queue_delay_ms={$averageDelay}; avg_runtime_ms={$averageRuntime}",
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'outbox table unreachable'];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkReverb(): array
    {
        // Only probe when the application is actually broadcasting through Reverb —
        // BROADCAST_CONNECTION=null/log (tests, some deployments) has no server to
        // reach and that is not a failure.
        if (config('broadcasting.default') !== 'reverb') {
            return ['ok' => true, 'detail' => 'not configured'];
        }

        $connection = (array) config('broadcasting.connections.reverb', []);
        $appId = $connection['app_id'] ?? null;
        $options = (array) ($connection['options'] ?? []);
        $host = $options['host'] ?? '127.0.0.1';
        $port = (int) ($options['port'] ?? 8080);

        if ($appId === null || $appId === '') {
            return ['ok' => true, 'detail' => 'not configured'];
        }

        try {
            $socket = @fsockopen((string) $host, $port, $errno, $errstr, (float) config('operations.reverb.timeout_seconds', 2));

            if ($socket === false) {
                return ['ok' => false, 'detail' => 'unreachable'];
            }

            fclose($socket);

            return ['ok' => true, 'detail' => 'reachable'];
        } catch (\Throwable) {
            return ['ok' => false, 'detail' => 'unreachable'];
        }
    }
}
