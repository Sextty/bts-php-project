<?php

namespace Tests\Feature\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
                        'queue' => ['ok', 'detail'],
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

    public function test_error_envelope_carries_the_request_id(): void
    {
        $response = $this->getJson('/api/applications/999999', ['X-Request-Id' => 'trace-err-1']);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'trace-err-1');
    }
}