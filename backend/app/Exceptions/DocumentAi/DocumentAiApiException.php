<?php

namespace App\Exceptions\DocumentAi;

class DocumentAiApiException extends DocumentAiException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
