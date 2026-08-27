<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_bearer_token_authenticates(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_an_expired_bearer_token_is_rejected(): void
    {
        config(['sanctum.expiration' => 30]);
        $token = User::factory()->create()->createToken('test');
        $token->accessToken->forceFill(['created_at' => now()->subMinutes(31)])->save();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
