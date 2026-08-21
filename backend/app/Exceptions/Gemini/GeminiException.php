<?php

namespace App\Exceptions\Gemini;

use RuntimeException;

/**
 * Base class for every failure surfaced by the Gemini integration. Messages are deliberately
 * safe to log and to show to staff: they never contain the document content, the prompt, or the
 * API key.
 */
class GeminiException extends RuntimeException {}