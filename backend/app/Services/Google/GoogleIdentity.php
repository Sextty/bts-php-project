<?php

namespace App\Services\Google;

/**
 * What Google's token verification asserts — an identity, never a decision. Nothing downstream
 * of GoogleTokenVerifier trusts anything about this identity beyond what's in these fields.
 */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $givenName,
        public readonly ?string $familyName,
    ) {}
}
