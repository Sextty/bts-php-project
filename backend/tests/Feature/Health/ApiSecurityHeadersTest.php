<?php

namespace Tests\Feature\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiSecurityHeadersTest extends TestCase
{
    public function test_api_responses_are_private_and_hardened_against_browser_attacks(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertHeader('Cache-Control')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

        foreach (['no-store', 'max-age=0', 'must-revalidate', 'private'] as $directive) {
            $this->assertStringContainsString($directive, (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_hsts_is_sent_only_when_the_api_request_is_https(): void
    {
        $this->getJson('/api/health')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/health')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[DataProvider('localPortalOrigins')]
    public function test_each_local_portal_origin_is_allowed_by_cors(string $origin): void
    {
        $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/health')
            ->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function test_unconfigured_origin_is_not_allowed_by_cors(): void
    {
        $this->withHeaders([
            'Origin' => 'http://example.test:3000',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/health')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_reverb_uses_explicit_local_hosts_without_a_wildcard(): void
    {
        $allowedOrigins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertContains('localhost', $allowedOrigins);
        $this->assertContains('127.0.0.1', $allowedOrigins);
        $this->assertNotContains('*', $allowedOrigins);
    }

    /** @return iterable<string, array{string}> */
    public static function localPortalOrigins(): iterable
    {
        foreach (['localhost', '127.0.0.1'] as $host) {
            foreach ([3000, 3001, 3002, 3003] as $port) {
                $origin = "http://{$host}:{$port}";

                yield $origin => [$origin];
            }
        }
    }
}
