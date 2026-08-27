<?php

namespace App\Http\Controllers\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreditApplication\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Responses\ApiResponse;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Services\AuditLogService;
use App\Services\CreditApplicationService;
use App\Services\DocumentStorage\DocumentStorage;
use App\Services\DocumentSecurity\MalwareScanner;
use App\Services\DocumentSecurity\DocumentAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly CreditApplicationService $applications,
        private readonly AuditLogService $auditLog,
        private readonly DocumentStorage $storage,
        private readonly MalwareScanner $malwareScanner,
        private readonly DocumentAccessPolicy $documentAccess,
    ) {}

    public function store(UploadDocumentRequest $request, CreditApplication $application): JsonResponse
    {
        $this->applications->assertEditable($application);

        $file = $request->file('file');
        $scan = $this->malwareScanner->scan($file->getRealPath());
        $scanMode = config('credit_documents.malware_scan.mode', 'optional');

        if ($scan['status'] === 'infected') {
            $this->auditLog->log(
                'credit_application.document_malware_rejected',
                $application->user,
                newState: ['document_type' => $request->string('document_type'), 'signature' => $scan['signature']],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                application: $application,
            );

            throw new ApiException(ApiErrorCode::DocumentMalwareDetected);
        }

        if (in_array($scan['status'], ['unavailable', 'error'], true) && $scanMode === 'required') {
            throw new ApiException(ApiErrorCode::MalwareScannerUnavailable);
        }
        // The storage layer sniffs the real content (finfo), validates it against the MIME
        // allowlist, and generates the stored name itself — a UUID + the extension that matches
        // the actual bytes, never the customer-supplied original filename (path traversal /
        // spoofed-extension defence). original_filename is kept separately for display/download.
        $path = $this->storage->store($application, $file);

        try {
            $document = DB::transaction(function () use ($application, $request, $file, $path, $scan) {
                $document = $application->documents()->create([
                    'document_type' => $request->string('document_type'),
                    'original_filename' => $file->getClientOriginalName(),
                    'disk_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'malware_scan_status' => $scan['status'],
                    'malware_signature' => $scan['signature'],
                    'malware_scanned_at' => in_array($scan['status'], ['clean', 'infected'], true) ? now() : null,
                ]);

                $this->auditLog->log(
                    'credit_application.document_uploaded',
                    $application->user,
                    newState: ['document_type' => $document->document_type, 'document_id' => $document->id],
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                    application: $application,
                );

                return $document;
            });
        } catch (\Throwable $e) {
            // Compensation: the file was already written to storage; if the DB row (or its audit
            // trail) can't be committed, the file must not be left orphaned on the disk.
            $this->storage->delete($path);
            Log::error('[documents] upload failed, stored file removed', [
                'application_id' => $application->id,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        return ApiResponse::created(['document' => new DocumentResource($document)]);
    }

    /**
     * Authorized download for the application's own customer. Authorization happens here
     * (ownership policy + application/document match) — the file itself lives on a private
     * disk with no public URL, and the response streams through the API.
     */
    public function download(Request $request, CreditApplication $application, Document $document): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('view', $application);

        if ($document->credit_application_id !== $application->id || $document->trashed()) {
            throw new ApiException(ApiErrorCode::DocumentNotFound);
        }

        $this->documentAccess->assertDownloadable($document);

        return $this->storage->response(
            $document->disk_path,
            $document->original_filename,
            $document->mime_type,
        );
    }

    public function destroy(Request $request, CreditApplication $application, Document $document): JsonResponse
    {
        $this->authorize('update', $application);

        if ($document->credit_application_id !== $application->id) {
            throw new ApiException(ApiErrorCode::DocumentNotFound);
        }

        $this->applications->assertEditable($application);

        $this->storage->delete($document->disk_path);
        $document->delete();

        $this->auditLog->log(
            'credit_application.document_deleted',
            $application->user,
            previousState: ['document_type' => $document->document_type, 'document_id' => $document->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            application: $application,
        );

        return ApiResponse::noContent();
    }
}
