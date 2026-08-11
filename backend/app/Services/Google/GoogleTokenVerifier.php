<?php

namespace App\Services\Google;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google-issued ID token via Google's tokeninfo endpoint (the officially documented
 * lightweight verification method — https://developers.google.com/identity/sign-in/web/backend-auth).
 * Only the id_token itself is ever accepted as input, never a client-asserted profile.
 *
 * A full offline JWKS-based verification (checking the signature locally against Google's public
 * keys, as the platform this replaces did) avoids the network round-trip and Google's tokeninfo
 * rate limit — worth doing as a follow-up hardening pass, not blocking this pass.
 */
class GoogleTokenVerifier
{
    public function verify(string $idToken): GoogleIdentity
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);

        if ($response->failed()) {
            throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed.', status: 401);
        }

        $payload = $response->json();

        $expectedAudience = config('services.google.client_id');
        if ($expectedAudience && ($payload['aud'] ?? null) !== $expectedAudience) {
            throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed.', status: 401);
        }

        $issuer = $payload['iss'] ?? null;
        if (! in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed.', status: 401);
        }

        if (empty($payload['sub'])) {
            throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed.', status: 401);
        }

        return new GoogleIdentity(
            sub: $payload['sub'],
            email: $payload['email'] ?? null,
            emailVerified: ($payload['email_verified'] ?? 'false') === 'true',
            givenName: $payload['given_name'] ?? null,
            familyName: $payload['family_name'] ?? null,
        );
    }
}
