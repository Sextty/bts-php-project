<?php

namespace Tests\Feature\CreditApplication;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentUploadTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_uploading_a_required_document_type_succeeds(): void
    {
        $application = $this->newApplication();

        $response = $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.document.document_type', 'cin')
            ->assertJsonPath('data.document.original_filename', 'cin.pdf');

        $this->assertSame(1, $application->documents()->count());
    }

    public function test_an_unconfigured_document_type_is_rejected(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'not_a_real_type',
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 20000, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_document_can_be_deleted_while_the_application_is_editable(): void
    {
        $application = $this->newApplication();

        $upload = $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);
        $documentId = $upload->json('data.document.id');

        $this->deleteJson("/api/applications/{$application->id}/documents/{$documentId}")
            ->assertOk();

        $this->assertSame(0, $application->documents()->count());
    }
}
