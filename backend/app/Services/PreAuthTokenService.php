<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bridges "password/Google verified" to "OTP verify" without the client ever holding or
 * resubmitting a raw, guessable user id. Mirrors the pre_auth_token pattern from the platform
 * this replaces: an opaque, short-lived, single-purpose token the client must present to the
 * verify-otp endpoint. Cache-backed rather than a signed JWT — no extra dependency, same
 * security property (unguessable, expiring, scoped to one purpose).
 */
class PreAuthTokenService
{
    private const TTL_MINUTES = 5;

    public function issue(User $user, string $purpose): string
    {
        $token = Str::random(64);
        Cache::put($this->key($token), ['user_id' => $user->id, 'purpose' => $purpose], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    public function resolve(string $token, string $purpose): User
    {
        $payload = Cache::get($this->key($token));

        if (! $payload || $payload['purpose'] !== $purpose) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        $user = User::find($payload['user_id']);
        if (! $user) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        return $user;
    }

    public function invalidate(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return "pre_auth_token:{$token}";
    }
}
