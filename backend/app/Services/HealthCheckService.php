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
        $queue = $this->checkQueue($database, $redis);
        $reverb = $this->checkReverb();

        $checks = [
            'database' => $database,
            'cache' => $cache,
            'redis' => $redis,
            'storage' => $storage,
            'queue' => $queue,
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
            default => $database['ok']
                ? ['ok' => true, 'detail' => 'database backing store ok']
                : ['ok' => false, 'detail' => 'database unreachable'],
        };
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

        $appId = env('REVERB_APP_ID');
        $host = env('REVERB_HOST', '127.0.0.1');
        $port = (int) env('REVERB_PORT', 8080);

        if ($appId === null || $appId === '') {
            return ['ok' => true, 'detail' => 'not configured'];
        }

        try {
            $socket = @fsockopen((string) $host, $port, $errno, $errstr, 2.0);

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