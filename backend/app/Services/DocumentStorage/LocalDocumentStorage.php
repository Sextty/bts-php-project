<?php

namespace App\Services\DocumentStorage;

/**
 * Development/staging storage: plain files under storage/app/documents on the
 * application server. The disk is configured with visibility 'private' and is never
 * linked into public/ — no URL to these files ever exists, downloads always flow
 * through the application's authorized endpoints.
 */
class LocalDocumentStorage extends BaseDocumentStorage
{
    protected function disk(): string
    {
        return self::DISK_ROOT_NAME;
    }
}