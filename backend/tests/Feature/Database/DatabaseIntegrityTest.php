<?php

namespace Tests\Feature\Database;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\CreditApplication\CreditApplicationTestCase;

/**
 * Schema-level guarantees of the database hardening migrations, plus the storage/DB
 * consistency behaviour of the new transactional document upload.
 */
class DatabaseIntegrityTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_dashboard_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('credit_applications', ['status']));
        $this->assertTrue(Schema::hasIndex('credit_applications', ['created_at']));
        $this->assertTrue(Schema::hasIndex('audit_logs', ['credit_application_id']));
    }

    public function test_documents_disk_path_is_unique(): void
    {
        $application = $this->newApplication();

        Document::create([
            'credit_application_id' => $application->id,
            'document_type' => 'cin',
            'original_filename' => 'a.pdf',
            'disk_path' => 'application-'.$application->id.'/same.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Document::create([
            'credit_application_id' => $application->id,
            'document_type' => 'cin',
            'original_filename' => 'b.pdf',
            'disk_path' => 'application-'.$application->id.'/same.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
    }

    public function test_a_failed_document_upload_leaves_no_row_and_no_orphaned_file(): void
    {
        // The application is created directly (bypassing the API) so the mocked audit backend
        // only interferes with the upload's own audit write.
        $application = CreditApplication::query()->forceCreate([
            'user_id' => $this->user->id,
            'status' => CreditApplication::STATUS_DRAFT,
        ]);

        $this->mock(AuditLogService::class, function ($mock) {
            $mock->allows('log')->andThrow(new RuntimeException('audit backend down'));
        });

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 fake pdf payload'),
        ])->assertStatus(500);

        // The DB row never committed…
        $this->assertSame(0, $application->documents()->count());
        // …and the file that was already written to storage was compensated away.
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    public function test_a_failed_client_save_rolls_back_the_whole_step(): void
    {
        $application = CreditApplication::query()->forceCreate([
            'user_id' => $this->user->id,
            'status' => CreditApplication::STATUS_DRAFT,
        ]);

        $this->mock(AuditLogService::class, function ($mock) {
            $mock->allows('log')->andThrow(new RuntimeException('audit backend down'));
        });

        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload())
            ->assertStatus(500);

        // The client row, the status bump and the code_client counter consumption all rolled
        // back together — the application is still an untouched DRAFT.
        $application->refresh();
        $this->assertNull($application->client);
        $this->assertSame(CreditApplication::STATUS_DRAFT, $application->status);
        $this->assertSame(0, DB::table('application_number_counters')->where('type', 'CL')->count());
    }

    public function test_appointment_attempt_number_is_unique_per_application(): void
    {
        $application = CreditApplication::factory()->create();
        $branch = Branch::factory()->create();
        $attributes = [
            'credit_application_id' => $application->id,
            'branch_id' => $branch->id,
            'attempt_number' => 1,
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '09:00:00',
            'status' => Appointment::STATUS_PROPOSED,
        ];

        Appointment::create($attributes);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Appointment::create(array_merge($attributes, ['scheduled_time' => '11:00:00']));
    }
}
