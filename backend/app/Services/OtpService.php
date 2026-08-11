<?php

namespace App\Services;

use App\Contracts\SmsProviderInterface;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * OTP generation, dispatch, and verification (auth plan Step 4).
 *
 * Security properties, each deliberate:
 *   - 6 digits via random_int (crypto-safe, uniform), never mt_rand/array_rand.
 *   - Hashed with the same hasher as passwords (Hash::make) — never stored plaintext, never
 *     logged plaintext by this class (the plaintext code is returned once, to the caller, for
 *     dispatch only).
 *   - 5-minute expiry, 5 max verify attempts, 60-second request cooldown — all three configurable
 *     via config('services.otp.*') / OTP_* env vars.
 *   - Generating a new OTP invalidates any prior unconsumed one for the same user+purpose. The
 *     platform this replaces did NOT do this (an old code stayed independently valid until its
 *     own expiry) — this closes that gap rather than carrying it forward.
 *   - Consumption is a conditional UPDATE ... WHERE consumed_at IS NULL, not a read-then-write,
 *     so two concurrent submissions of the same valid code cannot both succeed.
 */
class OtpService
{
    public function __construct(
        private readonly SmsProviderInterface $smsProvider,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * True if a new OTP was requested for this user+purpose within the cooldown window — checked
     * BEFORE generating, so a client can't bypass the cooldown by hitting this method's caller
     * directly (the route-level throttle middleware is a second, independent layer on top).
     */
    public function isRequestThrottled(User $user, string $purpose): bool
    {
        $cooldownSeconds = (int) config('services.otp.request_cooldown_seconds');

        return OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->exists();
    }

    /**
     * Generates, persists, and dispatches a new OTP. Returns the plaintext code only so the
     * caller can decide how to report dispatch failure — nothing else in the application ever
     * reads it back out (only the hash is stored).
     */
    public function generateAndSend(User $user, string $purpose, ?string $ip = null, ?string $userAgent = null): string
    {
        $length = (int) config('services.otp.length');
        $ttlMinutes = (int) config('services.otp.ttl_minutes');

        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $purpose, $code, $ttlMinutes) {
            // Invalidate every prior unconsumed code for this user+purpose — a fix versus the
            // platform being replaced, which left old codes independently valid.
            OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            OtpCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'purpose' => $purpose,
                'channel' => 'sms',
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });

        $message = "Your BTS Bank verification code is {$code}. It expires in {$ttlMinutes} minutes.";
        $result = $this->smsProvider->send($user->phone, $message);

        $this->auditLog->log(
            $result->ok ? 'otp.requested' : 'otp.dispatch_failed',
            $user,
            newState: ['purpose' => $purpose, 'channel' => 'sms'],
            ipAddress: $ip,
            userAgent: $userAgent,
        );

        if (! $result->ok) {
            throw new ApiException(
                'OTP_DISPATCH_FAILED',
                'Could not send the verification code. Please try again.',
                status: 503,
            );
        }

        return $code;
    }

    /**
     * Verifies a code for the given user+purpose. Throws ApiException on any failure — callers
     * (controllers) let it propagate to the API exception handler, which maps errorCode/status.
     */
    public function verify(User $user, string $purpose, string $code, ?string $ip = null, ?string $userAgent = null): void
    {
        $otp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            $this->auditLog->log('otp.failed', $user, newState: ['purpose' => $purpose, 'reason' => 'no_active_code'], ipAddress: $ip, userAgent: $userAgent);
            throw new ApiException('OTP_INVALID', 'Invalid or expired verification code.');
        }

        $maxAttempts = (int) config('services.otp.max_attempts');
        if ($otp->attempt_count >= $maxAttempts) {
            $this->auditLog->log('otp.exhausted', $user, newState: ['purpose' => $purpose], ipAddress: $ip, userAgent: $userAgent);
            throw new ApiException('MAX_ATTEMPTS_EXCEEDED', 'Too many incorrect attempts. Request a new code.', status: 429);
        }

        if ($otp->isExpired()) {
            $this->auditLog->log('otp.failed', $user, newState: ['purpose' => $purpose, 'reason' => 'expired'], ipAddress: $ip, userAgent: $userAgent);
            throw new ApiException('OTP_EXPIRED', 'This verification code has expired.', status: 410);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            // Atomic increment (UPDATE ... SET attempt_count = attempt_count + 1), not a
            // read-modify-write, so concurrent wrong guesses can't undercount.
            $otp->increment('attempt_count');
            if ($otp->attempt_count >= $maxAttempts) {
                $otp->update(['consumed_at' => now()]);
                $this->auditLog->log('otp.exhausted', $user, newState: ['purpose' => $purpose], ipAddress: $ip, userAgent: $userAgent);
                throw new ApiException('MAX_ATTEMPTS_EXCEEDED', 'Too many incorrect attempts. Request a new code.', status: 429);
            }
            $this->auditLog->log('otp.failed', $user, newState: ['purpose' => $purpose, 'reason' => 'wrong_code'], ipAddress: $ip, userAgent: $userAgent);
            throw new ApiException('OTP_INVALID', 'Invalid or expired verification code.');
        }

        // Conditional UPDATE, not read-then-write: two concurrent submissions of the same valid
        // code can only have one of them win this race.
        $consumed = DB::table('otp_codes')
            ->where('id', $otp->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            // Lost the race to another request verifying the same code concurrently.
            throw new ApiException('OTP_INVALID', 'Invalid or expired verification code.');
        }

        $this->auditLog->log('otp.verified', $user, newState: ['purpose' => $purpose], ipAddress: $ip, userAgent: $userAgent);
    }
}
