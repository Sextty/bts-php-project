<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Google Authentication -> check phone verification -> [request phone ->] send OTP -> verify OTP
 * -> authenticated. Every case here goes through the real controller flow with Google's
 * tokeninfo endpoint faked via Http::fake() — nothing about phone-verification gating is mocked.
 */
class GoogleAuthTest extends AuthTestCase
{
    private function fakeGoogleToken(array $overrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(array_merge([
                'sub' => 'google-subject-123',
                'email' => 'newgoogleuser@example.com',
                'email_verified' => 'true',
                'given_name' => 'Amel',
                'family_name' => 'Riahi',
                'aud' => config('services.google.client_id', ''),
                'iss' => 'https://accounts.google.com',
            ], $overrides)),
        ]);
    }

    public function test_a_brand_new_google_user_with_no_phone_is_asked_for_one_before_any_otp(): void
    {
        $this->fakeGoogleToken();

        $response = $this->postJson('/api/auth/google', ['google_id_token' => 'fake-token']);

        $response->assertOk()
            ->assertJsonPath('data.requires_phone', true)
            ->assertJsonMissingPath('data.access_token');

        $this->assertCount(0, $this->sms->sent);

        $user = User::where('google_id', 'google-subject-123')->firstOrFail();
        $this->assertSame('google', $user->auth_provider);
        $this->assertNull($user->password);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_setting_the_phone_triggers_an_otp_and_verifying_it_issues_a_token(): void
    {
        $this->fakeGoogleToken();
        $start = $this->postJson('/api/auth/google', ['google_id_token' => 'fake-token']);
        $preAuthToken = $start->json('data.pre_auth_token');

        $setPhone = $this->postJson('/api/auth/google/set-phone', [
            'pre_auth_token' => $preAuthToken,
            'phone' => '+21622334455',
        ]);
        $setPhone->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertCount(1, $this->sms->sent);

        $verify = $this->postJson('/api/auth/google/verify-otp', [
            'pre_auth_token' => $setPhone->json('data.pre_auth_token'),
            'otp_code' => $this->sms->lastCode(),
        ]);

        $verify->assertOk()->assertJsonStructure(['data' => ['access_token', 'user']]);

        $user = User::where('google_id', 'google-subject-123')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
    }

    public function test_a_returning_google_user_with_a_verified_phone_gets_a_token_immediately_no_otp(): void
    {
        $existing = User::factory()->create([
            'google_id' => 'google-subject-456',
            'auth_provider' => 'google',
            'password' => null,
        ]);

        $this->fakeGoogleToken(['sub' => 'google-subject-456', 'email' => $existing->email]);

        $response = $this->postJson('/api/auth/google', ['google_id_token' => 'fake-token']);

        $response->assertOk()->assertJsonStructure(['data' => ['access_token', 'user']]);
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_a_first_time_google_sign_in_matching_an_existing_unverified_email_is_rejected(): void
    {
        // Google says this address is NOT verified on their side — must not be trusted to link
        // to (or create) any account, even if the email string matches an existing user.
        $existing = User::factory()->create(['email' => 'maybe-fake@example.com']);

        $this->fakeGoogleToken([
            'sub' => 'google-subject-789',
            'email' => 'maybe-fake@example.com',
            'email_verified' => 'false',
        ]);

        $response = $this->postJson('/api/auth/google', ['google_id_token' => 'fake-token']);

        $response->assertStatus(401)->assertJsonPath('error.code', 'GOOGLE_TOKEN_INVALID');
        $this->assertNull($existing->fresh()->google_id);
    }

    public function test_an_existing_verified_email_is_linked_to_the_google_identity_not_duplicated(): void
    {
        $existing = User::factory()->create(['email' => 'link-me@example.com']);
        $this->assertNull($existing->google_id);

        $this->fakeGoogleToken(['sub' => 'google-subject-link', 'email' => 'link-me@example.com']);

        $this->postJson('/api/auth/google', ['google_id_token' => 'fake-token'])->assertOk();

        $this->assertSame(1, User::where('email', 'link-me@example.com')->count());
        $this->assertSame('google-subject-link', $existing->fresh()->google_id);
    }

    public function test_a_token_with_the_wrong_audience_is_rejected(): void
    {
        config(['services.google.client_id' => 'expected-client-id']);
        $this->fakeGoogleToken(['aud' => 'some-other-client-id']);

        $response = $this->postJson('/api/auth/google', ['google_id_token' => 'fake-token']);

        $response->assertStatus(401)->assertJsonPath('error.code', 'GOOGLE_TOKEN_INVALID');
    }
}
