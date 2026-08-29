<?php

namespace App\Services\DocumentStorage;

use App\Models\CreditApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Production storage on AWS S3 or any S3-compatible store (MinIO, Ceph, Scaleway...).
 * Selected by setting DOCUMENTS_DISK=s3 + the AWS_* env vars (endpoint and
 * use_path_style_endpoint make S3-compatible stores work — see config/filesystems.php).
 *
 * Files are written with private visibility and downloads are streamed THROUGH the
 * application by the authorized download controllers — a temporaryUrl or bucket URL is
 * never generated, so no document can be fetched outside the API's authorization layer.
 */
class S3CompatibleDocumentStorage extends BaseDocumentStorage
{
    protected function disk(): string
    {
        return self::DISK_ROOT_NAME;
    }

    public function store(CreditApplication $application, UploadedFile $file): string
    {
        $path = parent::store($application, $file);

        // Belt and braces: the disk config already defaults to private visibility, but an
        // operator overriding it must not be able to make uploads public by accident.
        Storage::disk($this->disk())->setVisibility($path, 'private');

        return $path;
    }
}