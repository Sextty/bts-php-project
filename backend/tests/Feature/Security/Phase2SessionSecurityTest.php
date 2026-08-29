<?php

namespace Tests\Feature\Security;

use App\Broadcasting\ApplicationChannel;
use App\Broadcasting\ApplicationReportChannel;
use App\Broadcasting\UserChannel;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_endpoint_rejects_staff_identity(): void
    {
        $staff = StaffUser::factory()->create();
        $token = $staff->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertForbidden();
    }

    public function test_suspended_customer_token_is_revoked_on_use(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/user')->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => User::class, 'tokenable_id' => $user->id]);
    }

    public function test_suspended_staff_token_is_revoked_on_use(): void
    {
        $staff = StaffUser::factory()->create(['status' => 'active']);
        $token = $staff->createToken('test')->plainTextToken;
        $staff->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/staff/notifications')->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => StaffUser::class, 'tokenable_id' => $staff->id]);
    }

    public function test_suspended_customer_cannot_join_any_private_customer_channel(): void
    {
        $user = User::factory()->create(['status' => 'suspended']);
        $application = CreditApplication::factory()->create(['user_id' => $user->id]);

        $this->assertFalse((new UserChannel)->join($user, $user->id));
        $this->assertFalse((new ApplicationChannel)->join($user, $application->id));
        $this->assertFalse((new ApplicationReportChannel)->join($user, $application->id));
    }
}
