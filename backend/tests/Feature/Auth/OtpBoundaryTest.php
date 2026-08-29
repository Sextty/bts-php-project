<?php

namespace Tests\Feature\Auth;

use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;

/**
 * Dedicated boundary suite for OtpService — the exact conditions the auth plan calls out by
 * name: expiry, max-attempts, cooldown, and invalidate-prior-on-regenerate (the fix versus the
 * platform being replaced, which never invalidated an old code when a new one was issued).
 * Exercised directly against OtpService rather than through the HTTP layer, so each boundary is
 * one assertion instead of a full register/verify round trip per case.
 */
class OtpBoundaryTest extends AuthTestCase
{
    private function service(): OtpService
    {
        return $this->app->make(OtpService::class);
    }

    public function test_a_correct_but_expired_code_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->service()->generateAndSend($user, 'registration');

        OtpCode::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        try {
            $this->service()->verify($user, 'registration', $code);
            $this->fail('expected OTP_EXPIRED');
        } catch (ApiException $e) {
            $this->assertSame('OTP_EXPIRED', $e->errorCode);
        }
    }

    public function test_five_wrong_attempts_exhausts_the_code_even_if_the_sixth_guess_would_be_correct(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->service()->generateAndSend($user, 'registration');

        for ($i = 0; $i < 4; $i++) {
            try {
                $this->service()->verify($user, 'registration', '000000');
                $this->fail('expected OTP_INVALID');
            } catch (ApiException $e) {
                $this->assertSame('OTP_INVALID', $e->errorCode);
            }
        }

        // The 5th wrong attempt trips MAX_ATTEMPTS_EXCEEDED, not another OTP_INVALID.
        try {
            $this->service()->verify($user, 'registration', '000000');
            $this->fail('expected MAX_ATTEMPTS_EXCEEDED');
        } catch (ApiException $e) {
            $this->assertSame('MAX_ATTEMPTS_EXCEEDED', $e->errorCode);
        }

        // The exhausted row was marked consumed_at on the 5th wrong attempt (see OtpService),
        // so it is no longer "active" at all — a further check reports OTP_INVALID (no active
        // code), not another MAX_ATTEMPTS_EXCEEDED. The important guarantee — that the correct
        // code cannot rescue an already-exhausted attempt — still holds either way.
        try {
            $this->service()->verify($user, 'registration', $code);
            $this->fail('expected the correct code to still be rejected once exhausted');
        } catch (ApiException $e) {
            $this->assertSame('OTP_INVALID', $e->errorCode);
        }
    }

    public function test_a_second_request_within_the_cooldown_window_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $this->service()->generateAndSend($user, 'registration');

        $this->assertTrue($this->service()->isRequestThrottled($user, 'registration'));
    }

    public function test_after_the_cooldown_window_a_new_request_is_not_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $this->service()->generateAndSend($user, 'registration');

        OtpCode::where('user_id', $user->id)->update([
            'created_at' => now()->subSeconds((int) config('services.otp.request_cooldown_seconds') + 5),
        ]);

        $this->assertFalse($this->service()->isRequestThrottled($user, 'registration'));
    }

    public function test_generating_a_new_otp_invalidates_the_previous_unconsumed_one(): void
    {
        $user = User::factory()->unverified()->create();
        $firstCode = $this->service()->generateAndSend($user, 'registration');

        // Bypass the cooldown directly (this test is about invalidation, not throttling).
        OtpCode::where('user_id', $user->id)->update(['created_at' => now()->subMinutes(5)]);
        $secondCode = $this->service()->generateAndSend($user, 'registration');

        $this->assertNotSame($firstCode, $secondCode);

        // The first code is dead even though it was never used or expired on its own —
        // this is the explicit fix versus the platform being replaced.
        try {
            $this->service()->verify($user, 'registration', $firstCode);
            $this->fail('expected the first code to have been invalidated');
        } catch (ApiException $e) {
            $this->assertSame('OTP_INVALID', $e->errorCode);
        }

        // The second (current) code still works.
        $this->service()->verify($user, 'registration', $secondCode);
        $this->assertNotNull(
            OtpCode::where('user_id', $user->id)->latest('id')->first()->consumed_at
        );
    }

    public function test_a_code_cannot_be_consumed_twice(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->service()->generateAndSend($user, 'registration');

        $this->service()->verify($user, 'registration', $code);

        try {
            $this->service()->verify($user, 'registration', $code);
            $this->fail('expected OTP_INVALID on replay');
        } catch (ApiException $e) {
            $this->assertSame('OTP_INVALID', $e->errorCode);
        }
    }
}
