<?php

namespace Tests\Feature\Notifications;

use App\Events\NotificationSent;
use App\Jobs\DeliverNotificationJob;
use App\Models\AppNotification;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_notify_user_persists_one_row_and_dispatches_in_app_and_email_delivery(): void
    {
        Event::fake([NotificationSent::class]);
        Bus::fake([DeliverNotificationJob::class]);

        $user = User::factory()->create();

        $notification = $this->service()->notifyUser($user, 'document.rejected', 'Document flagged', 'Please re-upload.', ['document_id' => 3], 'document-3');

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('app_notifications', [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'type' => 'document.rejected',
            'dedupe_key' => 'document-3',
        ]);

        Event::assertDispatched(NotificationSent::class, fn (NotificationSent $event) => $event->notification->id === $notification->id);
        Bus::assertDispatched(DeliverNotificationJob::class, fn (DeliverNotificationJob $job) => $job->notificationId === $notification->id && $job->channel === 'email');
    }

    public function test_same_type_and_dedupe_key_is_ignored_on_replay(): void
    {
        $user = User::factory()->create();
        $service = $this->service();

        $service->notifyUser($user, 'document.rejected', 'Document flagged', 'Please re-upload.', dedupeKey: 'document-3');

        // Simulate the same event firing twice (double submit, queue retry).
        $second = $service->notifyUser($user, 'document.rejected', 'Document flagged', 'Please re-upload.', dedupeKey: 'document-3');

        $this->assertNull($second);
        $this->assertSame(1, AppNotification::forNotifiable($user)->where('type', 'document.rejected')->count());
    }

    public function test_distinct_dedupe_keys_produce_separate_notifications(): void
    {
        $user = User::factory()->create();
        $service = $this->service();

        $service->notifyUser($user, 'appointment.created', 'Appointment', 'First.', dedupeKey: 'appointment-1');
        $service->notifyUser($user, 'appointment.changed', 'Appointment', 'Second.', dedupeKey: 'appointment-2');

        $this->assertSame(2, AppNotification::forNotifiable($user)->count());
    }

    public function test_report_messages_without_dedupe_key_are_all_kept(): void
    {
        $user = User::factory()->create();
        $service = $this->service();

        $service->notifyUser($user, 'report.message', 'New message', 'One.');
        $service->notifyUser($user, 'report.message', 'New message', 'Two.');

        $this->assertSame(2, AppNotification::forNotifiable($user)->where('type', 'report.message')->count());
    }

    public function test_notify_staff_reaches_every_active_staff_member_only(): void
    {
        $activeA = StaffUser::factory()->create();
        $activeB = StaffUser::factory()->create();
        StaffUser::factory()->create(['status' => 'suspended']);

        $created = $this->service()->notifyStaff('application.submitted', 'New application', 'Submitted.', dedupeKey: 'application-submitted-9');

        $this->assertCount(2, $created);

        foreach ([$activeA->id, $activeB->id] as $id) {
            $this->assertDatabaseHas('app_notifications', [
                'notifiable_type' => StaffUser::class,
                'notifiable_id' => $id,
                'type' => 'application.submitted',
            ]);
        }
    }

    public function test_notify_admins_reaches_admin_rank_and_above_only(): void
    {
        $admin = StaffUser::factory()->admin()->create();
        $regular = StaffUser::factory()->create();

        $this->service()->notifyAdmins('admin.approved', 'Approved', 'Done.');

        $this->assertSame(1, AppNotification::forNotifiable($admin)->count());
        $this->assertSame(0, AppNotification::forNotifiable($regular)->count());
    }

    public function test_staff_notifications_are_deduplicated_per_staff_member(): void
    {
        $service = $this->service();
        StaffUser::factory()->create();

        $service->notifyStaff('application.submitted', 'New application', 'One.', dedupeKey: 'application-submitted-9');
        $service->notifyStaff('application.submitted', 'New application', 'Two.', dedupeKey: 'application-submitted-9');

        $this->assertSame(1, AppNotification::where('type', 'application.submitted')->count());
    }

    public function test_notification_sent_broadcasts_on_the_right_private_channel(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::factory()->create();
        $admin = StaffUser::factory()->admin()->create();

        $userNotification = $this->service()->notifyUser($user, 'staff.approved', 'Approved', 'Body.');
        $staffNotification = $this->service()->notifyStaff('application.submitted', 'New', 'Body.')[0];
        $adminNotification = $this->service()->notifyAdmins('admin.approved', 'New', 'Body.')[0];

        $this->assertSame('private-user.'.$user->id, $this->channelOf(new NotificationSent($userNotification)));
        $this->assertSame('private-staff', $this->channelOf(new NotificationSent($staffNotification)));
        $this->assertSame('private-admin', $this->channelOf(new NotificationSent($adminNotification)));
    }

    private function channelOf(NotificationSent $event): string
    {
        return $event->broadcastOn()[0]->name;
    }
}
