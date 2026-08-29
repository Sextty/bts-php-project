<?php

namespace Tests\Feature\SyntheticData;

use App\Models\Branch;
use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSyntheticDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_materialized_mode_creates_safe_files_matching_document_metadata(): void
    {
        Storage::fake('documents');
        $this->createBranch();

        $arguments = [
            '--profile' => 'small',
            '--customers' => 20,
            '--applications' => 26,
            '--seed' => 'materialized-files-2026',
            '--chunk' => 10,
            '--materialize-documents' => true,
        ];

        $this->artisan('bts:generate-data', $arguments)->assertSuccessful();

        $documents = Document::query()->get();
        $this->assertNotEmpty($documents);
        foreach ($documents as $document) {
            Storage::disk('documents')->assertExists($document->disk_path);
            $content = Storage::disk('documents')->get($document->disk_path);
            $this->assertStringStartsWith('SYNTHETIC TEST DOCUMENT - NOT A REAL BANK RECORD', $content);
            $this->assertSame(strlen($content), $document->size_bytes);
            $this->assertSame('text/plain', $document->mime_type);
        }

        $count = $documents->count();
        $this->artisan('bts:generate-data', $arguments)->assertSuccessful();
        $this->assertDatabaseCount('documents', $count);
    }

    public function test_production_override_requires_every_independent_factor(): void
    {
        $this->createBranch();
        $originalEnvironment = $this->app->environment();
        $token = str_repeat('production-test-secret-', 2);
        $confirmation = 'GENERATE_SYNTHETIC_TEST_DATA';
        $base = [
            '--profile' => 'small',
            '--customers' => 10,
            '--applications' => 13,
            '--seed' => 'production-gate-2026',
            '--chunk' => 10,
            '--metadata-only-documents' => true,
        ];

        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'synthetic_data.production.enabled' => true,
            'synthetic_data.production.token' => $token,
        ]);

        try {
            $this->artisan('bts:generate-data', [
                ...$base,
                '--production-confirmation' => $confirmation,
            ])->assertFailed();
            $this->artisan('bts:generate-data', [
                ...$base,
                '--allow-production' => true,
                '--production-confirmation' => $confirmation,
            ])->expectsQuestion('Production authorization token', 'wrong-token')->assertFailed();
            $this->artisan('bts:generate-data', [
                ...$base,
                '--allow-production' => true,
                '--production-confirmation' => 'WRONG_PHRASE',
            ])->expectsQuestion('Production authorization token', $token)->assertFailed();

            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('staff_users', 0);

            $this->artisan('bts:generate-data', [
                ...$base,
                '--allow-production' => true,
                '--production-confirmation' => $confirmation,
            ])->expectsQuestion('Production authorization token', $token)->assertSuccessful();

            $this->assertSame(10, User::query()->where('email', 'like', 'syn-%@synthetic.bts.invalid')->count());
        } finally {
            $this->app->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_conflicting_document_modes_are_rejected_before_database_writes(): void
    {
        $this->artisan('bts:generate-data', [
            '--profile' => 'small',
            '--customers' => 10,
            '--applications' => 13,
            '--chunk' => 10,
            '--materialize-documents' => true,
            '--metadata-only-documents' => true,
        ])->assertExitCode(Command::INVALID);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('staff_users', 0);
    }

    private function createBranch(): Branch
    {
        return Branch::factory()->default()->create([
            'name' => 'BTS Agence Synthétique Test',
            'ville' => 'Tunis Test',
            'delegation' => 'Délégation Test',
            'address' => 'Adresse synthétique',
            'phone' => '+21670000001',
            'latitude' => 36.8065000,
            'longitude' => 10.1815000,
            'daily_capacity' => 4,
            'slot_start_time' => '09:00:00',
            'slot_end_time' => '16:00:00',
        ]);
    }
}
