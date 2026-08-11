<?php

namespace App\Http\Controllers\CreditApplication;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreditApplication\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Services\AuditLogService;
use App\Services\CreditApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(
        private readonly CreditApplicationService $applications,
        private readonly AuditLogService $auditLog,
    ) {}

    public function store(UploadDocumentRequest $request, CreditApplication $application): JsonResponse
    {
        $this->applications->assertEditable($application);

        $file = $request->file('file');
        // Store under a generated name, never the customer-supplied original filename — avoids
        // path traversal / collisions; original_filename is kept separately for display/download.
        $storedName = (string) Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('application-'.$application->id, $storedName, 'documents');

        $document = $application->documents()->create([
            'document_type' => $request->string('document_type'),
            'original_filename' => $file->getClientOriginalName(),
            'disk_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        $this->auditLog->log(
            'credit_application.document_uploaded',
            $application->user,
            newState: ['document_type' => $document->document_type, 'document_id' => $document->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            application: $application,
        );

        return response()->json([
            'success' => true,
            'data' => ['document' => new DocumentResource($document)],
        ], 201);
    }

    public function destroy(Request $request, CreditApplication $application, Document $document): JsonResponse
    {
        $this->authorize('update', $application);

        if ($document->credit_application_id !== $application->id) {
            throw new ApiException('DOCUMENT_NOT_FOUND', 'Document not found.', status: 404);
        }

        $this->applications->assertEditable($application);

        Storage::disk('documents')->delete($document->disk_path);
        $document->delete();

        $this->auditLog->log(
            'credit_application.document_deleted',
            $application->user,
            previousState: ['document_type' => $document->document_type, 'document_id' => $document->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            application: $application,
        );

        return response()->json(['success' => true, 'data' => null]);
    }
}
