<?php

namespace App\Services\Gemini;

use App\Contracts\GeminiClientInterface;
use App\Exceptions\Gemini\GeminiApiException;
use App\Exceptions\Gemini\GeminiConfigurationException;
use App\Exceptions\Gemini\GeminiMalformedResponseException;
use Illuminate\Http\Client\ConnectionException;
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

    public function generateContent(string $mimeType, string $base64Contents, string $prompt): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new GeminiConfigurationException('GEMINI_API_KEY is not configured.');
        }

        $url = sprintf(self::API_URL, config('services.gemini.model'));
        $timeout = (int) config('services.gemini.timeout_seconds', 60);
        $connectTimeout = (int) config('services.gemini.connect_timeout_seconds', 10);
        $maxRetries = (int) config('services.gemini.max_retries', 2);
        $retryDelayMs = (int) config('services.gemini.retry_delay_ms', 1000);

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withHeaders(['X-goog-api-key' => $apiKey])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->post($url, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Contents]],
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => ['responseMimeType' => 'application/json'],
                    ]);

                if ($response->successful()) {
                    return $this->extractPayload($response->json());
                }

                if ($this->isTransientStatus($response->status()) && $attempt <= $maxRetries) {
                    $delay = $this->retryDelayMs($response, $attempt, $retryDelayMs);
                    $this->logRetry($response->status(), $attempt, $delay);
                    usleep($delay * 1000);

                    continue;
                }

                throw new GeminiApiException(
                    "Gemini request failed: HTTP {$response->status()}.",
                    status: $response->status(),
                );
            } catch (ConnectionException $e) {
                if ($attempt <= $maxRetries) {
                    $delay = $retryDelayMs * (2 ** ($attempt - 1));
                    $this->logRetry(0, $attempt, $delay);
                    usleep($delay * 1000);

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
    private function retryDelayMs(\Illuminate\Http\Client\Response $response, int $attempt, int $baseMs): int
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