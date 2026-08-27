<?php

namespace Tests\Feature\Documents;

use App\Models\CreditApplication;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CreditApplication\CreditApplicationTestCase;

class DocumentDownloadTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function uploadPdf(CreditApplication $application): Document
    {
        $response = $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 fake pdf payload'),
        ]);
        $response->assertStatus(201);

        return Document::findOrFail($response->json('data.document.id'));
    }

    public function test_the_owner_can_download_their_document(): void
    {
        $application = $this->newApplication();
        $document = $this->uploadPdf($application);

        $this->get("/api/applications/{$application->id}/documents/{$document->id}")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=cin.pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_another_customer_cannot_download_someone_elses_document(): void
    {
        $application = $this->newApplication();
        $document = $this->uploadPdf($application);

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger, ['*']);

        // Stranger's own application, other customer's document id — must not resolve.
        $strangerApplication = $this->newApplication();
        $this->get("/api/applications/{$strangerApplication->id}/documents/{$document->id}")
            ->assertStatus(404);

        // Direct hit on the owner's application — ownership policy denies (403).
        $this->get("/api/applications/{$application->id}/documents/{$document->id}")
            ->assertStatus(403);
    }

    public function test_a_deleted_document_is_not_downloadable(): void
    {
        $application = $this->newApplication();
        $document = $this->uploadPdf($application);

        $this->deleteJson("/api/applications/{$application->id}/documents/{$document->id}")
            ->assertOk();

        $this->get("/api/applications/{$application->id}/documents/{$document->id}")
            ->assertStatus(404);
    }

    public function test_upload_is_stored_under_an_isolated_server_generated_path(): void
    {
        $application = $this->newApplication();
        $document = $this->uploadPdf($application);

        // Path is server-generated: application-scoped folder + UUID name, never the client's
        // filename, and the content really lives on the (private) documents disk.
        $this->assertMatchesRegularExpression(
            '#^application-'.$application->id.'/[0-9a-f-]+\.pdf$#',
            $document->disk_path,
        );
        $this->assertTrue(Storage::disk('documents')->exists($document->disk_path));
        $this->assertSame(
            strlen('%PDF-1.4 fake pdf payload'),
            Storage::disk('documents')->size($document->disk_path),
        );
    }

    public function test_a_file_whose_content_mismatches_its_claimed_type_is_rejected(): void
    {
        $application = $this->newApplication();

        // Claims to be a PDF, but the content is an HTML page — finfo sniffs the real bytes
        // and the storage layer refuses to persist it under a lying name.
        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->createWithContent('cin.pdf', '<html><script>alert(1)</script></html>'),
        ])->assertStatus(422);

        $this->assertSame(0, $application->documents()->count());
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    public function test_infected_document_is_never_downloadable(): void
    {
        $application = $this->newApplication();
        $document = $this->uploadPdf($application);
        $document->update(['malware_scan_status' => 'infected']);

        $this->get("/api/applications/{$application->id}/documents/{$document->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'DOCUMENT_NOT_CLEAN');
    }

    public function test_required_scanner_policy_blocks_legacy_unscanned_download(): void
    {
        $application = $this->newApplication();
        $document = $this->uploadPdf($application);
        $document->update(['malware_scan_status' => 'unavailable']);
        config(['credit_documents.malware_scan.mode' => 'required']);

        $this->get("/api/applications/{$application->id}/documents/{$document->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'DOCUMENT_NOT_CLEAN');
    }
}
