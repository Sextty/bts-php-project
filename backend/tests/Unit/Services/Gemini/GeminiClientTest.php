<?php

namespace Tests\Unit\Services\Gemini;

use App\Exceptions\Gemini\GeminiApiException;
use App\Exceptions\Gemini\GeminiConfigurationException;
use App\Exceptions\Gemini\GeminiMalformedResponseException;
use App\Services\Gemini\GeminiHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private GeminiHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new GeminiHttpClient;
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'test-model',
            'services.gemini.timeout_seconds' => 5,
            'services.gemini.connect_timeout_seconds' => 2,
            'services.gemini.max_retries' => 2,
            'services.gemini.retry_delay_ms' => 10,
        ]);
    }

    private function envelope(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function callGemini(): array
    {
        return $this->client->generateContent('application/pdf', base64_encode('pdf-bytes'), 'Analyze this.');
    }

    public function test_throws_configuration_exception_without_api_key(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(GeminiConfigurationException::class);

        $this->callGemini();
    }

    public function test_returns_decoded_payload_on_success(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->envelope('{"is_valid":true}'), 200)]);

        $this->assertSame(['is_valid' => true], $this->callGemini());
    }

    public function test_sends_the_file_contents_with_the_expected_headers(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->envelope('{"is_valid":true}'), 200)]);

        $this->callGemini();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('X-goog-api-key', 'test-key')
                && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent'
                && $body['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'application/pdf'
                && $body['contents'][0]['parts'][0]['inline_data']['data'] === base64_encode('pdf-bytes')
                && $body['contents'][0]['parts'][1]['text'] === 'Analyze this.'
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json';
        });
    }

    public function test_strips_markdown_code_fences_from_the_text(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            $this->envelope("```json\n{\"is_valid\": true, \"confidence\": \"high\"}\n```"),
            200,
        )]);

        $this->assertSame(['is_valid' => true, 'confidence' => 'high'], $this->callGemini());
    }

    public function test_recovers_after_429_with_retry_after_header(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push('rate limited', 429, ['Retry-After' => 0])
            ->push($this->envelope('{"is_valid":true}'), 200)]);

        $this->assertSame(['is_valid' => true], $this->callGemini());

        Http::assertSentCount(2);
    }

    public function test_retries_5xx_failures_with_bounded_backoff_then_succeeds(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push('boom', 503)
            ->push('boom', 503)
            ->push($this->envelope('{"is_valid":true}'), 200)]);

        $this->assertSame(['is_valid' => true], $this->callGemini());

        Http::assertSentCount(3);
    }

    public function test_gives_up_after_max_retries_and_throws_api_exception(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push('boom', 503)
            ->push('boom', 503)
            ->push('boom', 503)]);

        try {
            $this->callGemini();
            $this->fail('Expected GeminiApiException.');
        } catch (GeminiApiException $e) {
            $this->assertSame(503, $e->status);
        }

        Http::assertSentCount(3);
    }

    public function test_4xx_errors_are_not_retried(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('bad request', 400)]);

        try {
            $this->callGemini();
            $this->fail('Expected GeminiApiException.');
        } catch (GeminiApiException $e) {
            $this->assertSame(400, $e->status);
        }

        Http::assertSentCount(1);
    }

    public function test_connection_failure_retries_then_throws_with_status_zero(): void
    {
        $attempts = 0;

        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('Connection timed out.');
        }]);

        try {
            $this->callGemini();
            $this->fail('Expected GeminiApiException.');
        } catch (GeminiApiException $e) {
            $this->assertSame(0, $e->status);
        }

        // 1 attempt + 2 retries (max_retries).
        $this->assertSame(3, $attempts);
    }

    public function test_missing_message_text_throws_malformed_response(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []], 200)]);

        $this->expectException(GeminiMalformedResponseException::class);

        $this->callGemini();
    }

    public function test_text_without_json_throws_malformed_response(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->envelope('This is not JSON.'), 200)]);

        $this->expectException(GeminiMalformedResponseException::class);

        $this->callGemini();
    }

    public function test_undecodable_json_throws_malformed_response(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->envelope('{broken json'), 200)]);

        $this->expectException(GeminiMalformedResponseException::class);

        $this->callGemini();
    }
}
