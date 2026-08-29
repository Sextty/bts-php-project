<?php

namespace Tests\Feature\LoadTesting;

use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SyntheticLoadTestSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = storage_path('framework/testing/load-testing-'.getmypid().'-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($this->temporaryDirectory);
        $this->app['env'] = 'loadtest';
        config()->set('app.env', 'loadtest');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('L', 32)));
        config()->set('load_testing.enabled', true);
        config()->set('load_testing.database_pattern', '/^:memory:$/');
        config()->set('load_testing.marker_directory', $this->temporaryDirectory);
        config()->set('load_testing.max_accounts', 10);
        config()->set('load_testing.max_applications_per_account', 3);
        $this->seed(BranchSeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_prepare_command_creates_only_synthetic_identities_and_signed_manifest(): void
    {
        $manifest = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'manifest.json';

        $this->artisan('bts:prepare-load-test', [
            '--accounts' => 3,
            '--applications-per-account' => 2,
            '--branch-mode' => 'single',
            '--seed' => 'fixed-seed',
            '--output' => $manifest,
        ])->assertSuccessful();

        $payload = json_decode((string) File::get($manifest), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('SYNTHETIC_LOAD_TEST_ONLY', $payload['marker']);
        $this->assertCount(3, $payload['customers']);
        $this->assertSame(2, $payload['applications_per_account']);
        $this->assertSame('single', $payload['branch_mode']);
        $this->assertCount(1, collect($payload['customers'])->pluck('branch.id')->unique());
        $this->assertStringEndsWith('@synthetic.bts.invalid', $payload['customers'][0]['email']);
        $this->assertStringStartsWith('+2161', $payload['customers'][0]['phone']);
        $this->assertArrayNotHasKey('access_token', $payload);
        $this->assertDatabaseCount('credit_applications', 0);

        $this->getJson('/api/load-test/status')
            ->assertOk()
            ->assertJsonPath('data.marker', 'SYNTHETIC_LOAD_TEST_ONLY')
            ->assertJsonPath('data.campaign_id', $payload['campaign_id'])
            ->assertJsonPath('data.synthetic_accounts', 3);

        $correctness = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'correctness.json';
        $this->artisan('bts:verify-load-test', ['--manifest' => $manifest, '--json' => $correctness])
            ->assertSuccessful();
        $this->assertTrue(json_decode((string) File::get($correctness), true)['passed']);
    }

    public function test_load_test_has_no_production_override(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.env', 'production');

        $this->artisan('bts:prepare-load-test', [
            '--accounts' => 1,
            '--output' => $this->temporaryDirectory.DIRECTORY_SEPARATOR.'blocked.json',
        ])->assertExitCode(2);

        $this->assertFileDoesNotExist($this->temporaryDirectory.DIRECTORY_SEPARATOR.'blocked.json');
    }

    public function test_status_endpoint_is_not_discoverable_without_valid_marker(): void
    {
        $this->getJson('/api/load-test/status')->assertNotFound();
    }
}
