<?php

namespace Tests\Feature\Health;

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

    public function test_security_center_origin_is_allowed_by_cors(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:3003',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/health')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3003');
    }
}
