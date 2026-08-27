<?php

namespace Tests\Feature\CreditApplication;

use App\Services\DocumentSecurity\MalwareScanner;
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
            'file' => $this->fakePdf('cin.pdf', 500),
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
            'file' => $this->fakePdf('doc.pdf', 100),
        ])->assertStatus(422);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 20000),
        ])->assertStatus(422);
    }

    public function test_malware_is_rejected_before_the_file_enters_document_storage(): void
    {
        $this->mock(MalwareScanner::class)
            ->shouldReceive('scan')
            ->once()
            ->andReturn(['status' => 'infected', 'signature' => 'Eicar-Test-Signature']);

        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('infected.pdf', 100),
        ])->assertStatus(422)->assertJsonPath('error.code', 'DOCUMENT_MALWARE_DETECTED');

        $this->assertSame(0, $application->documents()->count());
        Storage::disk('documents')->assertDirectoryEmpty('/');
    }

    public function test_required_scanner_mode_fails_closed_when_clamav_is_unavailable(): void
    {
        config(['credit_documents.malware_scan.mode' => 'required']);
        $this->mock(MalwareScanner::class)
            ->shouldReceive('scan')
            ->once()
            ->andReturn(['status' => 'unavailable', 'signature' => null]);

        $application = $this->newApplication();

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 100),
        ])->assertStatus(503)->assertJsonPath('error.code', 'MALWARE_SCANNER_UNAVAILABLE');
    }

    public function test_a_document_can_be_deleted_while_the_application_is_editable(): void
    {
        $application = $this->newApplication();

        $upload = $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => $this->fakePdf('cin.pdf', 500),
        ]);
        $documentId = $upload->json('data.document.id');

        $this->deleteJson("/api/applications/{$application->id}/documents/{$documentId}")
            ->assertOk();

        $this->assertSame(0, $application->documents()->count());
    }
}
