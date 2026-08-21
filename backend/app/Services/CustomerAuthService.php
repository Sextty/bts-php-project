<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Orchestrates the customer challenge-response auth flow (password → pre-auth token → OTP →
 * access token). Before this service existed, every auth controller (register, login, Google)
 * re-implemented the same tail: resolve pre-auth token → verify OTP → invalidate token →
 * create session token → audit. The controllers keep their own flow-specific concerns
 * (account creation, Google identity linking, phone capture); everything the three flows share
 * lives here.
 */
class CustomerAuthService
{
    private const PURPOSE = 'login';

    /** A real bcrypt hash of an unguessable value — never actually matched, only timed against. */
    private const DUMMY_HASH = '$2y$12$8pS8v0m3F1qFQhH7QeJmMOa1n9v9wq4o1QeJmMOa1n9v9wq4o1QeJ';

    public function __construct(
        private readonly OtpService $otp,
        private readonly PreAuthTokenService $preAuth,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Issues the single-purpose pre-auth token and dispatches the OTP for it. The two-step
     * challenge: an access token is only ever minted after completeOtpChallenge() succeeds.
     */
    public function beginOtpChallenge(User $user, string $purpose, ?string $ip = null, ?string $userAgent = null): string
    {
        $preAuthToken = $this->preAuth->issue($user, $purpose);

        $this->otp->generateAndSend($user, $purpose, $ip, $userAgent);

        return $preAuthToken;
    }

    /**
     * Password stage of the customer login challenge. Timing-safe against account enumeration
     * (always runs a Hash::check, even for unknown or Google-only accounts), audits each
     * outcome, then hands off to the OTP stage. Returns the pre-auth token for verifyOtp.
     */
    public function authenticateWithPassword(string $identifier, string $password, ?string $ip = null, ?string $userAgent = null): string
    {
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        // Always run a Hash::check, even for an unknown account or a Google-only account with no
        // local password — so response timing can't be used to enumerate valid accounts. The
        // platform being replaced called this equalizeVerificationTiming(); same reasoning here.
        if ($user && $user->password) {
            $passwordValid = Hash::check($password, $user->password);
        } else {
            Hash::check($password, self::DUMMY_HASH);
            $passwordValid = false;
        }

        if (! $passwordValid) {
            $this->auditLog->log('auth.login.failed', $user, ipAddress: $ip, userAgent: $userAgent);

            throw new ApiException(ApiErrorCode::InvalidCredentials);
        }

        if ($user->status !== 'active' || $user->isBanned()) {
            $this->auditLog->log('auth.login.rejected', $user, newState: ['reason' => 'suspended', 'banned_reason' => $user->banned_reason], ipAddress: $ip, userAgent: $userAgent);

            throw new ApiException(ApiErrorCode::AccountSuspended, $user->banned_reason ? "Votre compte a été suspendu par la banque. Motif : {$user->banned_reason}" : 'Votre compte est suspendu.');
        }

        if ($this->otp->isRequestThrottled($user, self::PURPOSE)) {
            throw new ApiException(ApiErrorCode::RateLimited);
        }

        $this->auditLog->log('auth.login.password_verified', $user, ipAddress: $ip, userAgent: $userAgent);

        return $this->beginOtpChallenge($user, self::PURPOSE, $ip, $userAgent);
    }

    /**
     * OTP stage shared by every challenge flow: resolves the pre-auth token, verifies the code,
     * invalidates the token, and mints the access token. Returns both the token and the
     * authenticated user — callers still need the user for flow-specific post-steps (marking
     * the phone verified) and for the response body.
     *
     * @return array{token: string, user: User}
     */
    public function completeOtpChallenge(string $preAuthToken, string $purpose, string $otpCode, string $via, ?string $ip = null, ?string $userAgent = null): array
    {
        $user = $this->preAuth->resolve($preAuthToken, $purpose);

        $this->otp->verify($user, $purpose, $otpCode, $ip, $userAgent);

        $this->preAuth->invalidate($preAuthToken);

        return [
            'token' => $this->issueSession($user, $via, $ip, $userAgent),
            'user' => $user,
        ];
    }

    /** Mints an access token and records the session in the audit trail. */
    public function issueSession(User $user, string $via, ?string $ip = null, ?string $userAgent = null): string
    {
        $token = $user->createToken('api')->plainTextToken;

        $this->auditLog->log('auth.session.created', $user, newState: ['via' => $via], ipAddress: $ip, userAgent: $userAgent);

        return $token;
    }
}
