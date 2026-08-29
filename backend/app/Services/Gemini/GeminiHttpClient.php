<?php

namespace App\Services\Gemini;

use App\Contracts\GeminiClientInterface;
use App\Exceptions\Gemini\GeminiApiException;
use App\Exceptions\Gemini\GeminiConfigurationException;
use App\Exceptions\Gemini\GeminiMalformedResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport for Gemini's generateContent endpoint. Owns every network concern of the
 * integration: key injection, timeout, connection timeout, bounded retries with exponential
 * backoff for transient failures (429/5xx/network), Retry-After honoring, and safe logging
 * (status + attempt only — never the prompt or the document contents).
 *
 * The caller decides policy (CreditApplicationValidationService treats a failure as advisory);
 * this class's job is to make a best-effort call and fail loudly when the API is unreachable.
 */
class GeminiHttpClient implements GeminiClientInterface
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const RESPONSE_JSON_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'is_valid' => ['type' => 'boolean'],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'comment' => ['type' => 'string', 'description' => 'Une phrase courte en français.'],
            'extracted_fields' => [
                'type' => 'object',
                'additionalProperties' => ['type' => ['string', 'null']],
            ],
            'mismatches' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string'],
                        'expected' => ['type' => ['string', 'null']],
                        'extracted' => ['type' => ['string', 'null']],
                        'severity' => ['type' => 'string', 'enum' => ['critical', 'warning']],
                    ],
                    'required' => ['field', 'expected', 'extracted', 'severity'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['is_valid', 'confidence', 'comment', 'extracted_fields', 'mismatches'],
        'additionalProperties' => false,
    ];

    public function generateContent(
        string $mimeType,
        string $base64Contents,
        string $prompt,
        ?int $timeBudgetSeconds = null,
    ): array {
        $apiKey = config('services.gemini.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new GeminiConfigurationException('GEMINI_API_KEY is not configured.');
        }

        $url = sprintf(self::API_URL, config('services.gemini.model'));
        $configuredTimeout = max(1, (int) config('services.gemini.timeout_seconds', 25));
        $timeBudgetSeconds = max(1, $timeBudgetSeconds ?? $configuredTimeout);
        $deadline = microtime(true) + $timeBudgetSeconds;
        $connectTimeout = (int) config('services.gemini.connect_timeout_seconds', 5);
        $maxRetries = (int) config('services.gemini.max_retries', 1);
        $retryDelayMs = (int) config('services.gemini.retry_delay_ms', 250);

        $attempt = 0;

        while (true) {
            $attempt++;
            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds <= 0.1) {
                throw new GeminiApiException(
                    'Gemini request stopped because the document-validation time budget was exhausted.'
                );
            }

            $attemptTimeout = max(1, min($configuredTimeout, (int) ceil($remainingSeconds)));
            $attemptConnectTimeout = max(1, min($connectTimeout, $attemptTimeout));

            try {
                $response = Http::withHeaders(['X-goog-api-key' => $apiKey])
                    ->acceptJson()
                    ->timeout($attemptTimeout)
                    ->connectTimeout($attemptConnectTimeout)
                    ->post($url, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Contents]],
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseJsonSchema' => self::RESPONSE_JSON_SCHEMA,
                            'candidateCount' => 1,
                            'maxOutputTokens' => (int) config('services.gemini.max_output_tokens', 512),
                            'temperature' => (float) config('services.gemini.temperature', 0.1),
                            'thinkingConfig' => [
                                'thinkingBudget' => (int) config('services.gemini.thinking_budget', 0),
                            ],
                        ],
                    ]);

                if ($response->successful()) {
                    return $this->extractPayload($response->json());
                }

                if ($this->isTransientStatus($response->status()) && $attempt <= $maxRetries) {
                    $delay = $this->retryDelayMs($response, $attempt, $retryDelayMs);
                    if (! $this->sleepWithinBudget($delay, $deadline)) {
                        throw new GeminiApiException(
                            "Gemini request failed: HTTP {$response->status()} (retry budget exhausted).",
                            status: $response->status(),
                        );
                    }
                    $this->logRetry($response->status(), $attempt, $delay);

                    continue;
                }

                throw new GeminiApiException(
                    "Gemini request failed: HTTP {$response->status()}.",
                    status: $response->status(),
                );
            } catch (ConnectionException $e) {
                if ($attempt <= $maxRetries) {
                    $delay = $retryDelayMs * (2 ** ($attempt - 1));
                    if (! $this->sleepWithinBudget($delay, $deadline)) {
                        throw new GeminiApiException(
                            'Gemini request failed: connection retry budget exhausted.',
                            previous: $e,
                        );
                    }
                    $this->logRetry(0, $attempt, $delay);

                    continue;
                }

                throw new GeminiApiException(
                    'Gemini request failed: the API could not be reached (timeout or network error).',
                    previous: $e,
                );
            }
        }
    }

    /** 429 = rate limited, 5xx = provider-side outage. Both are worth a bounded retry. */
    private function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Honors a Retry-After header when the API provides one, otherwise exponential backoff:
     * base * 2^(attempt-1), capped at 10s so a permanently failing call can't stall a request.
     */
    private function retryDelayMs(Response $response, int $attempt, int $baseMs): int
    {
        $retryAfter = (int) $response->header('Retry-After');

        if ($retryAfter > 0) {
            return min($retryAfter * 1000, 10_000);
        }

        return min($baseMs * (2 ** ($attempt - 1)), 10_000);
    }

    private function logRetry(int $status, int $attempt, int $delayMs): void
    {
        Log::warning('[gemini] transient failure, retrying', [
            'status' => $status === 0 ? 'connection/timeout' : $status,
            'attempt' => $attempt,
            'retry_delay_ms' => $delayMs,
        ]);
    }

    private function sleepWithinBudget(int $delayMs, float $deadline): bool
    {
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);

        if ($delayMs <= 0 || $delayMs >= $remainingMs - 100) {
            return false;
        }

        usleep($delayMs * 1000);

        return true;
    }

    /**
     * responseMimeType does not guarantee bare JSON in every mode, so tolerate code fences and
     * surrounding prose: grab the first JSON object block. Missing text / undecodable content is
     * a malformed response, not a verdict.
     *
     * @param  array<string, mixed>|null  $envelope
     * @return array<string, mixed>
     *
     * @throws GeminiMalformedResponseException
     */
    private function extractPayload(?array $envelope): array
    {
        $text = is_array($envelope) ? data_get($envelope, 'candidates.0.content.parts.0.text') : null;

        if (! is_string($text) || trim($text) === '') {
            throw new GeminiMalformedResponseException('Gemini returned no message content.');
        }

        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $fenced)) {
            $text = trim($fenced[1]);
        }

        if (! preg_match('/\{.*\}/s', $text, $matches)) {
            throw new GeminiMalformedResponseException('Gemini response did not contain a JSON object.');
        }

        $decoded = json_decode($matches[0], true);

        if (! is_array($decoded)) {
            throw new GeminiMalformedResponseException('Gemini response JSON could not be decoded.');
        }

        return $decoded;
    }
}
