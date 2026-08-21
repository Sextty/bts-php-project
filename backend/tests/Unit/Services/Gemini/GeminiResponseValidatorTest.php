<?php

namespace Tests\Unit\Services\Gemini;

use App\Exceptions\Gemini\GeminiMalformedResponseException;
use App\Services\Gemini\GeminiResponseValidator;
use PHPUnit\Framework\TestCase;

class GeminiResponseValidatorTest extends TestCase
{
    private GeminiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new GeminiResponseValidator;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'is_valid' => true,
            'confidence' => 'high',
            'comment' => 'Genuine document.',
            'extracted_fields' => ['nom' => 'Ben Salah'],
            'mismatches' => [],
        ], $overrides);
    }

    public function test_normalizes_a_valid_payload(): void
    {
        $result = $this->validator->validate($this->validPayload());

        $this->assertTrue($result['is_valid']);
        $this->assertSame('high', $result['confidence']);
        $this->assertSame('Genuine document.', $result['comment']);
        $this->assertSame(['nom' => 'Ben Salah'], $result['extracted_fields']);
        $this->assertSame([], $result['mismatches']);
    }

    public function test_normalizes_nullable_extracted_values(): void
    {
        $result = $this->validator->validate($this->validPayload([
            'extracted_fields' => ['nom' => null, 'date_naissance' => '1990-05-12'],
        ]));

        $this->assertSame(['nom' => null, 'date_naissance' => '1990-05-12'], $result['extracted_fields']);
    }

    public function test_mismatches_are_normalized_to_a_stable_shape(): void
    {
        $result = $this->validator->validate($this->validPayload([
            'mismatches' => [
                ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Autre', 'severity' => 'critical'],
                ['field' => 'date_naissance', 'severity' => 'warning'],
            ],
        ]));

        $this->assertSame([
            ['field' => 'nom', 'expected' => 'Ben Salah', 'extracted' => 'Autre', 'severity' => 'critical'],
            ['field' => 'date_naissance', 'expected' => null, 'extracted' => null, 'severity' => 'warning'],
        ], $result['mismatches']);
    }

    public function test_rejects_a_missing_required_field(): void
    {
        unset($this->validPayload()['confidence']);

        $payload = $this->validPayload(['confidence' => 'high']);
        unset($payload['confidence']);

        $this->expectException(GeminiMalformedResponseException::class);
        $this->expectExceptionMessage('confidence');

        $this->validator->validate($payload);
    }

    public function test_rejects_non_boolean_is_valid(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload(['is_valid' => 'yes']));
    }

    public function test_rejects_unknown_confidence_level(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload(['confidence' => 'very-high']));
    }

    public function test_rejects_empty_comment(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload(['comment' => '   ']));
    }

    public function test_rejects_non_array_extracted_fields(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload(['extracted_fields' => 'nom']));
    }

    public function test_rejects_non_array_mismatches(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload(['mismatches' => 'none']));
    }

    public function test_rejects_mismatch_without_field(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload([
            'mismatches' => [['severity' => 'critical']],
        ]));
    }

    public function test_rejects_unknown_mismatch_severity(): void
    {
        $this->expectException(GeminiMalformedResponseException::class);

        $this->validator->validate($this->validPayload([
            'mismatches' => [['field' => 'nom', 'severity' => 'fatal']],
        ]));
    }
}
