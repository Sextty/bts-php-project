<?php

namespace Tests\Feature\Auth;

use App\Models\User;

class RegistrationTest extends AuthTestCase
{
    public function test_register_creates_a_pending_user_and_sends_an_otp(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Amel',
            'last_name' => 'Riahi',
            'email' => 'amel@example.com',
            'phone' => '+21620123456',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user_id', 'pre_auth_token']]);

        $user = User::findOrFail($response->json('data.user_id'));
        $this->assertNull($user->phone_verified_at);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($user->phone, $this->sms->sent[0]['to']);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Amel',
            'last_name' => 'Riahi',
            'email' => 'taken@example.com',
            'phone' => '+21620999999',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_register_rejects_a_password_under_the_minimum_length(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Amel',
            'last_name' => 'Riahi',
            'email' => 'amel2@example.com',
            'phone' => '+21620123457',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ]);

        $response->assertStatus(422);
    }

    public function test_verify_registration_otp_with_the_correct_code_issues_a_token_and_verifies_phone(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'first_name' => 'Amel',
            'last_name' => 'Riahi',
            'email' => 'amel3@example.com',
            'phone' => '+21620123458',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $preAuthToken = $register->json('data.pre_auth_token');
        $code = $this->sms->lastCode();

        $response = $this->postJson('/api/auth/verify-registration-otp', [
            'pre_auth_token' => $preAuthToken,
            'otp_code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'user']]);

        $user = User::findOrFail($register->json('data.user_id'));
        $this->assertNotNull($user->fresh()->phone_verified_at);

        // The issued token actually authenticates a protected route.
        $token = $response->json('data.access_token');
        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('email', 'amel3@example.com');
    }

    public function test_verify_registration_otp_with_the_wrong_code_is_rejected_and_does_not_verify_phone(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'first_name' => 'Amel',
            'last_name' => 'Riahi',
            'email' => 'amel4@example.com',
            'phone' => '+21620123459',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ]);

        $response = $this->postJson('/api/auth/verify-registration-otp', [
            'pre_auth_token' => $register->json('data.pre_auth_token'),
            'otp_code' => '000000',
        ]);

        $response->assertStatus(400)->assertJsonPath('error.code', 'OTP_INVALID');

        $user = User::findOrFail($register->json('data.user_id'));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_verify_otp_with_an_unknown_pre_auth_token_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/verify-registration-otp', [
            'pre_auth_token' => 'not-a-real-token',
            'otp_code' => '123456',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');
    }
}
