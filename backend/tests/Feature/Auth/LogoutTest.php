<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class LogoutTest extends AuthTestCase
{
    public function test_logout_revokes_the_token_and_it_no_longer_authenticates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());

        // Sanctum's RequestGuard memoizes the resolved user for the guard instance's lifetime,
        // and the container isn't rebuilt between postJson()/getJson() calls within one test
        // method — so without this, the guard would still return the user it resolved during
        // the logout request above, even though the token row is already gone (confirmed via
        // the count() assertion). A real server rebuilds the guard fresh on every request, so
        // this reset only matters here, in the test harness, not in the LogoutController itself.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_logout_without_a_token_is_refused(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }

    public function test_logout_only_revokes_the_current_token_not_every_session(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $tokenB = $user->createToken('device-b')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/user')
            ->assertOk();
    }
}
