<?php

namespace App\Exceptions;

/**
 * One exception class carrying an error code, used across every auth controller (register,
 * login, Google, password reset) — not just OTP. Mirrors the DomainError(code, message) pattern
 * from the platform this replaces: a single, consistent {success:false, error:{code,message}}
 * envelope (rendered in bootstrap/app.php's withExceptions), rather than a subclass per failure
 * mode.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
