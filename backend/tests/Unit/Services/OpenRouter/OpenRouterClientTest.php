<?php

namespace Tests\Unit\Services\OpenRouter;

use App\Exceptions\DocumentAi\DocumentAiApiException;
use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\DocumentAi\DocumentAiMalformedResponseException;
use App\Services\OpenRouter\OpenRouterHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterClientTest extends TestCase
{
    private OpenRouterHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new OpenRouterHttpClient;
        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.model' => 'openrouter/free',
            'services.openrouter.http_referer' => 'http://localhost:8000',
            'services.openrouter.app_title' => 'BTS Bank Test',
            'services.openrouter.timeout_seconds' => 5,
            'services.openrouter.connect_timeout_seconds' => 2,
            'services.openrouter.max_retries' => 2,
            'services.openrouter.retry_delay_ms' => 1,
            'services.openrouter.max_output_tokens' => 768,
            'services.openrouter.temperature' => 0.1,
            'services.openrouter.reasoning_enabled' => true,
            'services.openrouter.pdf_engine' => 'cloudflare-ai',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function envelope(array $payload): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($payload)]]]];
    }

    private function callImage(): array
    {
        return $this->client->generateContent('image/png', base64_encode('image-bytes'), 'Analyse ce document.');
    }

    public function test_requires_api_key(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->expectException(DocumentAiConfigurationException::class);

        $this->callImage();
    }

    public function test_returns_decoded_structured_payload(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->envelope(['is_valid' => true]), 200)]);

        $this->assertSame(['is_valid' => true], $this->callImage());
    }

    public function test_sends_image_with_reasoning_and_json_object_mode(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->envelope(['is_valid' => true]), 200)]);

        $this->callImage();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasHeader('HTTP-Referer', 'http://localhost:8000')
                && $request->hasHeader('X-Title', 'BTS Bank Test')
                && ($body['model'] ?? null) === 'openrouter/free'
                && ($body['messages'][0]['content'][0]['type'] ?? null) === 'text'
                && ($body['messages'][0]['content'][1]['type'] ?? null) === 'image_url'
                && ($body['messages'][0]['content'][1]['image_url']['url'] ?? null)
                    === 'data:image/png;base64,'.base64_encode('image-bytes')
                && ($body['reasoning'] ?? null) === ['enabled' => true, 'exclude' => true]
                && ($body['response_format'] ?? null) === ['type' => 'json_object']
                && ! isset($body['plugins']);
        });
    }

    public function test_sends_pdf_as_private_base64_file_with_free_parser(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->envelope(['is_valid' => true]), 200)]);

        $this->client->generateContent('application/pdf', base64_encode('pdf-bytes'), 'Analyse ce PDF.');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['messages'][0]['content'][1]['type'] ?? null) === 'file'
                && ($body['messages'][0]['content'][1]['file']['filename'] ?? null) === 'document.pdf'
                && ($body['messages'][0]['content'][1]['file']['file_data'] ?? null)
                    === 'data:application/pdf;base64,'.base64_encode('pdf-bytes')
                && ($body['plugins'][0]['id'] ?? null) === 'file-parser'
                && ($body['plugins'][0]['pdf']['engine'] ?? null) === 'cloudflare-ai';
        });
    }

    public function test_retries_transient_failure_then_succeeds(): void
    {
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push('rate limited', 429)
            ->push($this->envelope(['is_valid' => true]), 200)]);

        $this->assertSame(['is_valid' => true], $this->callImage());
        Http::assertSentCount(2);
    }

    public function test_retry_after_cannot_exceed_the_caller_time_budget(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response('rate limited', 429, ['Retry-After' => '10'])]);
        $startedAt = microtime(true);

        try {
            $this->client->generateContent(
                'image/png',
                base64_encode('image-bytes'),
                'Analyse ce document.',
                1,
            );
            $this->fail('Expected DocumentAiApiException.');
        } catch (DocumentAiApiException $e) {
            $this->assertSame(429, $e->status);
        }

        $this->assertLessThan(1.0, microtime(true) - $startedAt);
        Http::assertSentCount(1);
    }

    public function test_does_not_retry_non_transient_client_error(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response('bad request', 400)]);

        try {
            $this->callImage();
            $this->fail('Expected DocumentAiApiException.');
        } catch (DocumentAiApiException $e) {
            $this->assertSame(400, $e->status);
        }

        Http::assertSentCount(1);
    }

    public function test_connection_failure_retries_then_throws(): void
    {
        $attempts = 0;

        Http::fake(['openrouter.ai/*' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Connection timed out.');
        }]);

        try {
            $this->callImage();
            $this->fail('Expected DocumentAiApiException.');
        } catch (DocumentAiApiException $e) {
            $this->assertSame(0, $e->status);
        }

        $this->assertSame(3, $attempts);
    }

    public function test_rejects_missing_or_invalid_message_content(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => []], 200)]);

        $this->expectException(DocumentAiMalformedResponseException::class);

        $this->callImage();
    }
}
