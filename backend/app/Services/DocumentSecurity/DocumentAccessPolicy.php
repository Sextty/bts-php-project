<?php

namespace App\Services\DocumentSecurity;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Document;

/** Prevents infected or production-unscanned files from leaving private storage. */
class DocumentAccessPolicy
{
    public function assertDownloadable(Document $document): void
    {
        $this->assertStatus((string) $document->malware_scan_status);
    }

    public function assertStatus(string $status): void
    {

        if ($status === 'infected') {
            throw new ApiException(ApiErrorCode::DocumentNotClean);
        }

        if (config('credit_documents.malware_scan.mode', 'optional') === 'required' && $status !== 'clean') {
            throw new ApiException(ApiErrorCode::DocumentNotClean);
        }
    }
}
