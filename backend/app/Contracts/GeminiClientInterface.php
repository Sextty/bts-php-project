<?php

namespace App\Contracts;

/**
 * Isolated transport layer for the Gemini (generativelanguage) API. The only class in the app
 * that knows about the wire format — headers, URL, retries, timeouts, rate limits. Callers
 * (DocumentVerificationService) consume the decoded payload and never touch the API directly.
 *
 * Implementations must:
 *   - throw GeminiConfigurationException when the API key is not configured;
 *   - throw GeminiApiException (status 0) for connection failures/timeouts after retries;
 *   - throw GeminiApiException with the HTTP status for 4xx/5xx after retries;
 *   - retry transient failures (429, 5xx, network) with bounded exponential backoff;
 *   - never log the prompt or the file contents.
 */
interface GeminiClientInterface
{
    /**
     * @return array<string, mixed> The decoded JSON object from the model's message text.
     *
     * @throws \App\Exceptions\Gemini\GeminiConfigurationException
     * @throws \App\Exceptions\Gemini\GeminiApiException
     * @throws \App\Exceptions\Gemini\GeminiMalformedResponseException
     */
    public function generateContent(string $mimeType, string $base64Contents, string $prompt): array;
}