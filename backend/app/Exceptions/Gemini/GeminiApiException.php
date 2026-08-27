<?php

namespace App\Exceptions\Gemini;

/**
 * The Gemini API rejected or failed the request: HTTP 4xx/5xx, connection failure or timeout
 * after retries. Carries the HTTP status (or 0 for transport failures) so callers can tell a
 * configuration error (401/403) from an outage (503) from a dead network (0).
 */
class GeminiApiException extends GeminiException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
