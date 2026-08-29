<?php

namespace App\ValueObjects;

/**
 * The OTP payload handed to a transport provider (SmsProviderInterface::send). Replaces the old
 * flat-text contract where every driver had to scrape the code and TTL back out of the message
 * string (EmailOtpDriver regexed it) — the values are now first-class, and the pre-formatted
 * body is just the human-readable fallback for channels that send plain text.
 */
final class OtpMessage
{
    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
        public readonly string $body,
    ) {}
}
