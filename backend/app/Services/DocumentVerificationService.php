<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sends an uploaded document to a vision-capable LLM via OpenRouter (one OpenAI-compatible API
 * in front of many providers' free models) for an authenticity/legibility check. Advisory only —
 * callers decide what to do with a failure; this service never silently swallows one, it throws
 * and lets the caller (CreditApplicationValidationService) log-and-continue.
 */
class DocumentVerificationService
{
    /**
     * @return array{is_valid: bool, confidence: string, comment: string}
     */
    public function verify(Document $document): array
    {
        $apiKey = config('services.openrouter.api_key');

        if (! $apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $fileContents = Storage::disk('documents')->get($document->disk_path);

        if ($fileContents === null) {
            throw new RuntimeException("Document file not found on disk: {$document->disk_path}");
        }

        $dataUrl = 'data:'.$document->mime_type.';base64,'.base64_encode($fileContents);

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => config('services.openrouter.model'),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $dataUrl],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'You are reviewing an identity/proof document (e.g. national ID card, '
                                    ."passport, residence card) submitted as part of a bank credit application. "
                                    .'Assess whether it looks like a genuine, legible, unaltered document of that '
                                    .'kind — not whether the applicant is creditworthy. Respond with ONLY a JSON '
                                    .'object: {"is_valid": boolean, "confidence": "high"|"medium"|"low", '
                                    .'"comment": "one short sentence explaining the verdict"}.',
                            ],
                        ],
                    ],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'document_verification',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'is_valid' => ['type' => 'boolean'],
                                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                                'comment' => ['type' => 'string'],
                            ],
                            'required' => ['is_valid', 'confidence', 'comment'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenRouter request failed: HTTP {$response->status()} {$response->body()}");
        }

        $content = $response->json('choices.0.message.content');

        if (! $content) {
            throw new RuntimeException('OpenRouter returned no message content.');
        }

        // Not every free model honors response_format strictly — some wrap the JSON in prose or
        // a code fence despite the instruction, so extract the first {...} block rather than
        // assuming $content is bare JSON.
        if (! preg_match('/\{.*\}/s', $content, $matches)) {
            throw new RuntimeException("OpenRouter response did not contain JSON: {$content}");
        }

        $result = json_decode($matches[0], true);

        if (! is_array($result) || ! isset($result['is_valid'], $result['confidence'], $result['comment'])) {
            throw new RuntimeException("OpenRouter response JSON missing expected fields: {$content}");
        }

        return [
            'is_valid' => (bool) $result['is_valid'],
            'confidence' => (string) $result['confidence'],
            'comment' => (string) $result['comment'],
        ];
    }
}
