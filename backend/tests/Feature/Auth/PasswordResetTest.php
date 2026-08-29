<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;

class PasswordResetTest extends AuthTestCase
{
    public function test_forgot_password_returns_the_same_response_for_a_known_and_unknown_email(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/password/forgot', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.com']);

        // Same status and shape either way — enumeration is not something this endpoint reveals.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('data.message'), $unknown->json('data.message'));
    }

    public function test_reset_with_a_valid_token_changes_the_password_and_revokes_existing_sessions(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $oldToken = $user->createToken('api')->plainTextToken;

        $resetToken = Password::createToken($user);

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'BrandNewPassword123',
            'password_confirmation' => 'BrandNewPassword123',
        ]);

        $response->assertOk();

        $login = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'BrandNewPassword123',
        ]);
        $login->assertOk();

        // Every session that existed before the reset is revoked.
        $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_reset_with_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'BrandNewPassword123',
            'password_confirmation' => 'BrandNewPassword123',
        ]);

        $response->assertStatus(400)->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
    }

    public function test_reset_password_below_the_minimum_length_is_rejected(): void
    {
        $user = User::factory()->create();
        $resetToken = Password::createToken($user);

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ]);

        $response->assertStatus(422);
    }
}
