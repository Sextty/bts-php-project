<?php

namespace App\Logging;

use Monolog\LogRecord;

/**
 * Last-line protection for structured technical logs. Domain services should already avoid PII,
 * but this processor prevents an accidental context array from serialising credentials, personal
 * contact data, document bytes, HTTP headers, or business payloads.
 */
final class SanitizeLogContextProcessor
{
    /** @var list<string> */
    private const REDACTED_KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'authorization', 'cookie', 'otp', 'api_key',
        'email', 'phone', 'address', 'user_agent', 'ip_address', 'document_content',
        'file_content', 'payload', 'headers', 'request_body', 'previous_state', 'new_state',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->sanitize($record->context),
            extra: $this->sanitize($record->extra),
        );
    }

    /** @param array<string|int, mixed> $context @return array<string|int, mixed> */
    private function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($this->mustRedact($normalizedKey)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            // Avoid accidental multi-megabyte log lines when an exception context contains a
            // third-party response. Identifiers and scalar operational values remain intact.
            $sanitized[$key] = is_string($value) && strlen($value) > 1_024
                ? substr($value, 0, 1_024).'…[TRUNCATED]'
                : $value;
        }

        return $sanitized;
    }

    private function mustRedact(string $key): bool
    {
        foreach (self::REDACTED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
