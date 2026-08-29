<?php

namespace Tests\Feature\Health;

use Tests\TestCase;

class ConfigCacheHealthTest extends TestCase
{
    public function test_operational_health_configuration_is_cacheable(): void
    {
        try {
            $this->artisan('config:cache')->assertSuccessful();
            $path = base_path('bootstrap/cache/config.php');
            $this->assertFileExists($path);
            $cached = require $path;

            $this->assertArrayHasKey('operations', $cached);
            $this->assertArrayHasKey('worker', $cached['operations']);
            $this->assertArrayHasKey('scheduler', $cached['operations']);
            $this->assertArrayHasKey('reverb', $cached['broadcasting']['connections']);
        } finally {
            $this->artisan('config:clear')->assertSuccessful();
        }
    }

    public function test_health_service_contains_no_runtime_env_access(): void
    {
        $source = file_get_contents(app_path('Services/HealthCheckService.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('env(', $source);
    }
}
