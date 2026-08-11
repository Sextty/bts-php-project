<?php

namespace App\Services\Sms;

/**
 * Result shape a provider returns for one send attempt. `transient` distinguishes a fault worth
 * retrying (provider timeout, rate limit) from a permanent one (invalid number) — mirrors the
 * old platform's ChannelProvider ok/transient/error contract, carried forward because it made
 * retry-vs-give-up decisions trivial to write against.
 */
final class SmsDeliveryResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $transient = false,
        public readonly ?string $error = null,
    ) {}

    public static function success(): self
    {
        return new self(ok: true);
    }

    public static function failure(string $error, bool $transient = false): self
    {
        return new self(ok: false, transient: $transient, error: $error);
    }
}
