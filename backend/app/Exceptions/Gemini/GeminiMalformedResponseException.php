<?php

namespace App\Exceptions\Gemini;

/**
 * The Gemini call succeeded but the payload was not usable JSON — either the envelope did not
 * contain a message text, or the text could not be decoded / did not satisfy the strict schema.
 * Treated as a failed verification (advisory path), never as a verdict.
 */
class GeminiMalformedResponseException extends GeminiException {}