<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCode;

/**
 * One exception class carrying an error code, used across every auth controller (register,
 * login, Google, password reset) and every domain flow. Mirrors the DomainError(code, message)
 * pattern from the platform this replaces: a single, consistent {success:false, error:{code,
 * message}} envelope (rendered in bootstrap/app.php's withExceptions), rather than a subclass
 * per failure mode.
 *
 * The error code is an ApiErrorCode enum, so code strings can never be misspelled and the
 * default HTTP status + client message live in one place. Dynamic messages (e.g. the stage name
 * in INVALID_APPLICATION_STATUS) and status overrides (e.g. MAX_ATTEMPTS_EXCEEDED for
 * appointment proposals) can still be passed per site; both are optional and fall back to the
 * enum's defaults.
 *
 * `errorCode` and `status` stay public so existing tests and the exception renderer keep
 * reading the exact same surface they did before.
 */
class ApiException extends \RuntimeException
{
    public readonly string $errorCode;

    public readonly int $status;

    public function __construct(
        public readonly ApiErrorCode $error,
        ?string $message = null,
        ?int $status = null,
    ) {
        $this->errorCode = $error->value;
        $this->status = $status ?? $error->status();

        parent::__construct($message ?? $error->message());
    }
}
