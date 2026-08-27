<?php

namespace App\Contracts;

/**
 * Provider-neutral transport used by the advisory document-verification pipeline.
 * Implementations own their wire format, retries and safe error handling. They must never
 * log the prompt, the document contents, or provider credentials.
 */
interface DocumentAiClientInterface
{
    /** @return array<string, mixed> Decoded structured verdict returned by the model. */
    public function generateContent(
        string $mimeType,
        string $base64Contents,
        string $prompt,
        ?int $timeBudgetSeconds = null,
    ): array;
}
