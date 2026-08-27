<?php

namespace App\Services\DocumentVerification;

use App\Contracts\DocumentAiClientInterface;
use App\Exceptions\DocumentAi\DocumentAiConfigurationException;

/**
 * Local-only safe default. It deliberately never reads, transmits, or attempts
 * to infer information from a customer document. The existing validation flow
 * records the verification as unavailable and leaves the final decision to a
 * staff member, exactly as it already does for an unavailable provider.
 */
final class LocalOnlyDocumentVerificationClient implements DocumentAiClientInterface
{
    public function generateContent(
        string $mimeType,
        string $base64Contents,
        string $prompt,
        ?int $timeBudgetSeconds = null,
    ): array {
        throw new DocumentAiConfigurationException(
            'Cloud document verification is disabled in local-only mode. A staff member must review this document.'
        );
    }
}
