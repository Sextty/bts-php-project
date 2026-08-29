<?php

namespace App\Services\DocumentStorage;

use App\Models\CreditApplication;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Storage abstraction for credit-application documents. Two implementations exist:
 *
 *   - LocalDocumentStorage        — development/staging on the server's own disk
 *   - S3CompatibleDocumentStorage — production on AWS S3 or any S3-compatible store
 *     (MinIO, Ceph, Scaleway...), selected by the `documents` disk's driver
 *     (DOCUMENTS_DISK=AWS_* env vars, see config/filesystems.php).
 *
 * Both implementations guarantee the security invariants regardless of driver:
 *
 *   - private files: the disk is never publicly servable (no symlink into public/, no
 *     bucket URL handed out — downloads always stream through the application);
 *   - safe filenames: stored names are server-generated UUIDs with an extension derived
 *     from the sniffed content, never from client input;
 *   - MIME validation: content is sniffed with finfo and must match the configured
 *     allowlist (config/credit_documents.php) or the upload is rejected;
 *   - size limits: enforced here again (in addition to the FormRequest), so the limit
 *     holds no matter what calls the storage layer;
 *   - path isolation: every file lives under application-{id}/ and paths that attempt
 *     traversal (..) are rejected before touching the disk.
 */
interface DocumentStorage
{
    /**
     * Validate, name and persist an upload for an application.
     *
     * @throws \Illuminate\Validation\ValidationException when the content is not an
     *         allowed type or exceeds the configured size limit.
     */
    public function store(CreditApplication $application, UploadedFile $file): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    /**
     * Stream the file content back as a download response. Used only by the authorized
     * download controllers — nothing in the response exposes the underlying path or
     * storage URL, and the response is always attachment-dispositioned.
     */
    public function response(string $path, string $downloadName, string $mimeType): BinaryFileResponse|StreamedResponse;

    public function size(string $path): int;
}