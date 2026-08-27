<?php

namespace App\Services\OpenRouter;

use App\Contracts\DocumentAiClientInterface;
use App\Exceptions\DocumentAi\DocumentAiApiException;
use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiMalformedResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenRouter chat-completions transport for advisory document verification.
 * Prompts, document bytes, credentials and provider response bodies are never logged.
 */
final class OpenRouterHttpClient implements DocumentAiClientInterface
{
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
        $apiKey = config('services.openrouter.api_key');
        $model = config('services.openrouter.model');
        $baseUrl = rtrim((string) config('services.openrouter.base_url'), '/');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new DocumentAiConfigurationException('OPENROUTER_API_KEY is not configured.');
        }

        if (! is_string($model) || trim($model) === '') {
            throw new DocumentAiConfigurationException('OPENROUTER_MODEL is not configured.');
        }

        if (! str_starts_with($baseUrl, 'https://')) {
            throw new DocumentAiConfigurationException('OPENROUTER_BASE_URL must use HTTPS.');
        }

        $configuredTimeout = max(1, (int) config('services.openrouter.timeout_seconds', 45));
        $timeBudgetSeconds = max(1, $timeBudgetSeconds ?? $configuredTimeout);
        $deadline = microtime(true) + $timeBudgetSeconds;
        $connectTimeout = (int) config('services.openrouter.connect_timeout_seconds', 5);
        $maxRetries = (int) config('services.openrouter.max_retries', 1);
        $retryDelayMs = (int) config('services.openrouter.retry_delay_ms', 250);
        $attempt = 0;

        while (true) {
            $attempt++;
            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds <= 0.1) {
                throw new DocumentAiApiException(
                    'OpenRouter request stopped because the document-validation time budget was exhausted.'
                );
            }

            $attemptTimeout = max(1, min($configuredTimeout, (int) ceil($remainingSeconds)));
            $attemptConnectTimeout = max(1, min($connectTimeout, $attemptTimeout));

            try {
                $response = Http::withHeaders($this->headers($apiKey))
                    ->acceptJson()
                    ->asJson()
                    ->timeout($attemptTimeout)
                    ->connectTimeout($attemptConnectTimeout)
                    ->post($baseUrl.'/chat/completions', $this->requestPayload(
                        $model,
                        $mimeType,
                        $base64Contents,
                        $prompt,
                    ));

                if ($response->successful()) {
                    return $this->extractPayload($response->json());
                }

                if ($this->isTransientStatus($response->status()) && $attempt <= $maxRetries) {
                    $delay = $this->retryDelayMs($response, $attempt, $retryDelayMs);
                    if (! $this->sleepWithinBudget($delay, $deadline)) {
                        throw new DocumentAiApiException(
                            "OpenRouter request failed: HTTP {$response->status()} (retry budget exhausted).",
                            status: $response->status(),
                        );
                    }
                    $this->logRetry($response->status(), $attempt, $delay);

                    continue;
                }

                throw new DocumentAiApiException(
                    "OpenRouter request failed: HTTP {$response->status()}.",
                    status: $response->status(),
                );
            } catch (ConnectionException $e) {
                if ($attempt <= $maxRetries) {
                    $delay = min($retryDelayMs * (2 ** ($attempt - 1)), 10_000);
                    if (! $this->sleepWithinBudget($delay, $deadline)) {
                        throw new DocumentAiApiException(
                            'OpenRouter request failed: connection retry budget exhausted.',
                            previous: $e,
                        );
                    }
                    $this->logRetry(0, $attempt, $delay);

                    continue;
                }

                throw new DocumentAiApiException(
                    'OpenRouter request failed: the API could not be reached (timeout or network error).',
                    previous: $e,
                );
            }
        }
    }

    /** @return array<string, string> */
    private function headers(string $apiKey): array
    {
        $headers = ['Authorization' => 'Bearer '.$apiKey];
        $referer = trim((string) config('services.openrouter.http_referer'));
        $title = trim((string) config('services.openrouter.app_title'));

        if ($referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }

        if ($title !== '') {
            $headers['X-Title'] = $title;
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function requestPayload(string $model, string $mimeType, string $base64Contents, string $prompt): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        $dataUrl = "data:{$mimeType};base64,{$base64Contents}";

        if ($mimeType === 'application/pdf') {
            $content[] = [
                'type' => 'file',
                'file' => [
                    'filename' => 'document.pdf',
                    'file_data' => $dataUrl,
                ],
            ];
        } elseif (str_starts_with($mimeType, 'image/')) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUrl],
            ];
        } else {
            throw new DocumentAiConfigurationException("Unsupported AI document MIME type: {$mimeType}.");
        }

        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'stream' => false,
            'max_tokens' => (int) config('services.openrouter.max_output_tokens', 768),
            'temperature' => (float) config('services.openrouter.temperature', 0.1),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'bts_document_verification',
                    'strict' => true,
                    'schema' => self::RESPONSE_JSON_SCHEMA,
                ],
            ],
        ];

        if ((bool) config('services.openrouter.reasoning_enabled', true)) {
            // This workflow is single-turn, so there is no reasoning_details continuation to
            // preserve. Excluding it avoids retaining hidden analysis about customer documents.
            $payload['reasoning'] = ['enabled' => true, 'exclude' => true];
        }

        if ($mimeType === 'application/pdf') {
            $payload['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => (string) config('services.openrouter.pdf_engine', 'cloudflare-ai')],
            ]];
        }

        return $payload;
    }

    private function isTransientStatus(int $status): bool
    {
        return $status === 408 || $status === 409 || $status === 429 || $status >= 500;
    }

    private function retryDelayMs(Response $response, int $attempt, int $baseMs): int
    {
        $retryAfter = (int) $response->header('Retry-After');

        return $retryAfter > 0
            ? min($retryAfter * 1000, 10_000)
            : min($baseMs * (2 ** ($attempt - 1)), 10_000);
    }

    private function logRetry(int $status, int $attempt, int $delayMs): void
    {
        Log::warning('[openrouter] transient failure, retrying', [
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

    /** @param array<string, mixed>|null $envelope @return array<string, mixed> */
    private function extractPayload(?array $envelope): array
    {
        $content = is_array($envelope) ? data_get($envelope, 'choices.0.message.content') : null;

        if (is_array($content)) {
            $content = collect($content)
                ->filter(fn ($part) => is_array($part) && ($part['type'] ?? null) === 'text')
                ->pluck('text')
                ->implode("\n");
        }

        if (! is_string($content) || trim($content) === '') {
            throw new DocumentAiMalformedResponseException('OpenRouter returned no assistant message content.');
        }

        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $fenced)) {
            $content = trim($fenced[1]);
        }

        if (! preg_match('/\{.*\}/s', $content, $matches)) {
            throw new DocumentAiMalformedResponseException('OpenRouter response did not contain a JSON object.');
        }

        $decoded = json_decode($matches[0], true);

        if (! is_array($decoded)) {
            throw new DocumentAiMalformedResponseException('OpenRouter response JSON could not be decoded.');
        }

        return $decoded;
    }
}
