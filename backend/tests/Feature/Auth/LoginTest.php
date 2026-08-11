<?php

namespace Tests\Feature\Auth;

use App\Models\User;

class LoginTest extends AuthTestCase
{
    public function test_login_requires_otp_every_time_even_with_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'CorrectHorseBattery',
        ]);

        // Only a pre_auth_token — never an access token from the password step alone.
        $response->assertOk()
            ->assertJsonStructure(['data' => ['pre_auth_token']])
            ->assertJsonMissingPath('data.access_token');

        $this->assertCount(1, $this->sms->sent);
    }

    public function test_login_with_the_wrong_password_is_rejected_with_a_generic_message(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_with_an_unknown_identifier_gets_the_same_generic_message(): void
    {
        // Same error/status as a wrong password for a real account — enumeration is not
        // something a differently-worded response should reveal.
        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'WhateverPassword1',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_may_use_the_phone_number_as_the_identifier(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->phone,
            'password' => 'CorrectHorseBattery',
        ]);

        $response->assertOk();
    }

    public function test_a_suspended_account_is_refused_even_with_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery', 'status' => 'suspended']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'CorrectHorseBattery',
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_login_verify_otp_issues_a_working_access_token(): void
    {
        $user = User::factory()->create(['password' => 'CorrectHorseBattery']);

        $login = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'CorrectHorseBattery',
        ]);

        $response = $this->postJson('/api/auth/login/verify-otp', [
            'pre_auth_token' => $login->json('data.pre_auth_token'),
            'otp_code' => $this->sms->lastCode(),
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['access_token', 'user']]);

        $token = $response->json('data.access_token');
        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_a_google_only_account_cannot_log_in_with_a_password(): void
    {
        $user = User::factory()->google()->create();

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'AnyPassword1234',
        ]);

        // Same generic error as any other wrong credential — never a hint that this account
        // has no password to check against.
        $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertCount(0, $this->sms->sent);
    }
}
