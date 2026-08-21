<?php

namespace App\Exceptions\Gemini;

/**
 * The Gemini integration is not configured (missing API key / model). Never retried — the
 * configuration is not going to fix itself between attempts.
 */
class GeminiConfigurationException extends GeminiException {}