<?php

namespace App\Services\DocumentSecurity;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DocumentStorage\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportAttachmentService
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly MalwareScanner $scanner,
        private readonly DocumentAccessPolicy $access,
        private readonly AuditLogService $audit,
    ) {}

    /** @return array<string, mixed> */
    public function store(
        CreditApplication $application,
        UploadedFile $file,
        User|StaffUser $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $scan = $this->scanner->scan($file->getRealPath());

        if ($scan['status'] === 'infected') {
            $this->auditScanRejection('report_attachment.malware_rejected', $application, $actor, $ipAddress, $userAgent, $scan);
            throw new ApiException(ApiErrorCode::DocumentMalwareDetected);
        }

        if (in_array($scan['status'], ['unavailable', 'error'], true)
            && config('credit_documents.malware_scan.mode', 'optional') === 'required') {
            $this->auditScanRejection('report_attachment.scan_unavailable', $application, $actor, $ipAddress, $userAgent, $scan);
            throw new ApiException(ApiErrorCode::MalwareScannerUnavailable);
        }

        $path = $this->storage->store($application, $file);

        return [
            'attachment_path' => $path,
            'attachment_disk' => 'documents',
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_type' => $file->getMimeType(),
            'attachment_size' => $file->getSize(),
            'malware_scan_status' => $scan['status'],
            'malware_signature' => $scan['signature'],
            'malware_scanned_at' => $scan['status'] === 'clean' ? now() : null,
        ];
    }

    public function deleteStored(array $attachment): void
    {
        if (($attachment['attachment_disk'] ?? null) === 'documents' && isset($attachment['attachment_path'])) {
            $this->storage->delete((string) $attachment['attachment_path']);
        }
    }

    public function response(CreditApplication $application, ReportMessage $message): BinaryFileResponse|StreamedResponse
    {
        if ($message->credit_application_id !== $application->id || ! $message->attachment_path) {
            throw new ApiException(ApiErrorCode::DocumentNotFound, 'Pièce jointe introuvable.');
        }

        $this->access->assertStatus((string) $message->malware_scan_status);
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '_', $message->attachment_name ?? 'piece_jointe') ?? 'piece_jointe';

        if ($message->attachment_disk === 'documents') {
            return $this->storage->response($message->attachment_path, $name, $message->attachment_type ?: 'application/octet-stream');
        }

        $expectedPrefix = 'report_attachments/application-'.$application->id.'/';
        if (! str_starts_with($message->attachment_path, $expectedPrefix)
            || str_contains($message->attachment_path, '..')
            || str_contains($message->attachment_path, '\\')) {
            throw new RuntimeException('Unsafe report attachment path.');
        }

        if (! Storage::disk('local')->exists($message->attachment_path)) {
            throw new ApiException(ApiErrorCode::DocumentNotFound, 'Fichier introuvable sur le serveur.');
        }

        return Storage::disk('local')->response($message->attachment_path, $name, [
            'Content-Type' => $message->attachment_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }

    /** @param array{status: string, signature: ?string} $scan */
    private function auditScanRejection(
        string $action,
        CreditApplication $application,
        User|StaffUser $actor,
        ?string $ipAddress,
        ?string $userAgent,
        array $scan,
    ): void {
        $this->audit->log(
            $action,
            user: $actor instanceof User ? $actor : null,
            newState: ['scan_status' => $scan['status'], 'signature' => $scan['signature']],
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            application: $application,
            staffUser: $actor instanceof StaffUser ? $actor : null,
        );
    }
}
