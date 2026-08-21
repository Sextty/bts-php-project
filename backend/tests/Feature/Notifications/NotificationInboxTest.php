<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function inboxNotification(User $user, string $type = 'staff.approved', ?string $dedupeKey = null): AppNotification
    {
        return AppNotification::create([
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'type' => $type,
            'title' => 'Test title',
            'body' => 'Test body',
            'data' => ['application_id' => 1],
            'dedupe_key' => $dedupeKey,
        ]);
    }

    public function test_customer_sees_only_their_own_notifications(): void
    {
        $mine = $this->inboxNotification($this->user);
        $other = User::factory()->create();
        $this->inboxNotification($other);

        Sanctum::actingAs($this->user, ['*']);

        $response = $this->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.type', 'staff.approved');
    }

    public function test_mark_read_flags_the_requested_notification(): void
    {
        $first = $this->inboxNotification($this->user);
        $second = $this->inboxNotification($this->user, 'report.message');

        Sanctum::actingAs($this->user, ['*']);

        $this->postJson("/api/notifications/mark-read?id={$first->id}")->assertOk();

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
    }

    public function test_mark_read_all_is_idempotent(): void
    {
        $this->inboxNotification($this->user);
        $this->inboxNotification($this->user, 'report.message');

        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/notifications/mark-read')->assertOk();
        $this->postJson('/api/notifications/mark-read')->assertOk();

        $this->assertSame(0, AppNotification::forNotifiable($this->user)->unread()->count());
    }

    public function test_staff_inbox_is_staff_only(): void
    {
        $staff = StaffUser::factory()->create();
        $this->inboxNotification($this->user);
        AppNotification::create([
            'notifiable_type' => $staff->getMorphClass(),
            'notifiable_id' => $staff->id,
            'type' => 'application.submitted',
            'title' => 'New',
            'body' => 'Body',
        ]);

        Sanctum::actingAs($this->user, ['*']);
        $this->getJson('/api/staff/notifications')->assertStatus(403);

        Sanctum::actingAs($staff, ['*']);
        $this->getJson('/api/staff/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'application.submitted');
    }
}
