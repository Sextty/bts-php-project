<?php

namespace Tests\Feature\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public readiness endpoint and the request-correlation middleware.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config([
            'operations.worker.required' => false,
            // Storage::fake owns the write/read probe. Use an existing cross-platform path for
            // the separate capacity probe because Laravel's ephemeral fake root may not yet
            // exist on a fresh Linux runner.
            'filesystems.disks.documents.root' => base_path(),
        ]);
    }

    public function test_health_reports_every_component_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure([
                'success',
                'data' => [
                    'status',
                    'checks' => [
                        'database' => ['ok', 'detail'],
                        'cache' => ['ok', 'detail'],
                        'redis' => ['ok', 'detail'],
                        'storage' => ['ok', 'detail'],
                        'disk_space' => ['ok', 'detail'],
                        'queue' => ['ok', 'detail'],
                        'queue_worker' => ['ok', 'detail'],
                        'scheduler' => ['ok', 'detail'],
                        'failed_jobs' => ['ok', 'detail'],
                        'async_outbox' => ['ok', 'detail'],
                        'reverb' => ['ok', 'detail'],
                    ],
                ],
            ]);

        foreach ($response->json('data.checks') as $check) {
            $this->assertTrue($check['ok'], 'All checks must pass in a healthy environment.');
        }
    }

    public function test_health_needs_no_authentication(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    public function test_liveness_needs_no_dependency_probe(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['status' => 'ok']]);
    }

    public function test_operations_status_command_returns_machine_readable_readiness(): void
    {
        $this->artisan('operations:status', ['--json' => true])
            ->expectsOutputToContain('"status":"ok"')
            ->assertExitCode(0);
    }

    public function test_health_reports_low_disk_space_without_exposing_paths(): void
    {
        config(['operations.storage.min_free_bytes' => PHP_INT_MAX]);

        $response = $this->getJson('/api/health')->assertStatus(503);

        $this->assertFalse($response->json('data.checks.disk_space.ok'));
        $this->assertSame('low', $response->json('data.checks.disk_space.detail'));
        $this->assertStringNotContainsString(storage_path(), $response->getContent());
    }

    public function test_health_never_exposes_internal_details_on_failure(): void
    {
        // Break the cache store deliberately: an unusable store must surface as a
        // degraded status with a short detail — never an exception message.
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('secret connection string: mysql://root:pass@db:3306'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503);

        $this->assertSame('unreachable', $response->json('data.checks.cache.detail'));
        $this->assertStringNotContainsString('mysql://', $response->getContent());
    }

    public function test_health_detects_a_stalled_database_queue(): void
    {
        config(['queue.default' => 'database']);
        config(['operations.queue.max_ready_age_seconds' => 1]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinute()->timestamp,
            'created_at' => now()->subMinute()->timestamp,
        ]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('data.checks.queue.ok', false);

    }

    public function test_health_distinguishes_empty_queue_from_offline_worker(): void
    {
        config(['queue.default' => 'database', 'operations.worker.required' => true]);
        Cache::forget(config('operations.worker.heartbeat_key'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('data.checks.queue.ok', true)
            ->assertJsonPath('data.checks.queue_worker.ok', false)
            ->assertJsonPath('data.checks.queue_worker.detail', 'heartbeat missing');
    }

    public function test_health_reports_delayed_reserved_and_failed_jobs_separately(): void
    {
        config(['queue.default' => 'database', 'operations.queue.max_failed' => 0]);
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addMinute()->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'synthetic failure',
            'failed_at' => now(),
        ]);

        $response = $this->getJson('/api/health')->assertStatus(503);
        $this->assertStringContainsString('delayed=1', $response->json('data.checks.queue.detail'));
        $this->assertStringContainsString('reserved=1', $response->json('data.checks.queue.detail'));
        $this->assertFalse($response->json('data.checks.failed_jobs.ok'));
    }

    public function test_every_api_response_carries_a_request_id_header(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertHeader('X-Request-Id');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_a_client_supplied_request_id_is_honoured(): void
    {
        $response = $this->getJson('/api/health', ['X-Request-Id' => 'trace-abc-123']);

        $response->assertHeader('X-Request-Id', 'trace-abc-123');
    }

    public function test_request_completion_log_has_only_safe_operational_context(): void
    {
        Log::spy();

        $this->getJson('/api/health', [
            'X-Request-Id' => 'trace-log-123',
            'X-Correlation-Id' => 'correlation-456',
        ])->assertOk()->assertHeader('X-Correlation-Id', 'correlation-456');

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'api.request_completed'
                && $context['request_id'] === 'trace-log-123'
                && $context['correlation_id'] === 'correlation-456'
                && $context['event_category'] === 'http'
                && $context['route'] === 'api/health'
                && is_int($context['duration_ms'])
                && ! array_key_exists('headers', $context)
                && ! array_key_exists('payload', $context);
        });
    }

    public function test_error_envelope_carries_the_request_id(): void
    {
        $response = $this->getJson('/api/applications/999999', ['X-Request-Id' => 'trace-err-1']);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'trace-err-1');
    }
}
