<?php

namespace Tests\Feature\Async;

use App\Events\NotificationSent;
use App\Jobs\ProcessAsyncOutboxEventJob;
use App\Models\AppNotification;
use App\Models\AsyncOutboxEvent;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AsyncOutboxService;
use App\Services\NotificationService;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TransactionalOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_rollback_removes_notification_and_outbox_intent(): void
    {
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user): void {
                app(NotificationService::class)->notifyUser($user, 'test.rollback', 'Test', 'Rollback', dedupeKey: 'rollback-1');
                throw new RuntimeException('synthetic rollback');
            });
        } catch (RuntimeException) {
            // Expected synthetic rollback.
        }

        $this->assertDatabaseMissing('app_notifications', ['dedupe_key' => 'rollback-1']);
        $this->assertDatabaseMissing('async_outbox_events', ['dedupe_key' => 'notification-broadcast-1']);
    }

    public function test_repeated_intent_is_idempotent(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $notification = AppNotification::create([
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'type' => 'synthetic',
            'title' => 'Synthetic',
            'body' => 'Synthetic',
        ]);
        $outbox = app(AsyncOutboxService::class);

        $first = $outbox->record(AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST, $notification, [], 'same-intent');
        $second = $outbox->record(AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST, $notification, [], 'same-intent');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('async_outbox_events', 1);
    }

    public function test_staff_audience_is_one_request_intent_then_worker_scoped_fanout(): void
    {
        Queue::fake();
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $matching = StaffUser::factory()->forBranch($branch)->create();
        $other = StaffUser::factory()->forBranch($otherBranch)->create();
        $admin = StaffUser::factory()->admin()->create();
        $application = CreditApplication::factory()->create(['branch_id' => $branch->id]);

        app(NotificationService::class)->queueStaffAudience(
            $application,
            'synthetic.audience',
            'Synthetic',
            'Synthetic audience',
            ['branch_id' => $branch->id],
            'synthetic-audience-1',
        );

        $this->assertDatabaseCount('app_notifications', 0);
        $intent = AsyncOutboxEvent::where('type', AsyncOutboxService::TYPE_STAFF_AUDIENCE_NOTIFICATION)->sole();
        (new ProcessAsyncOutboxEventJob($intent->id))->handle(app(NotificationService::class));

        $recipients = AppNotification::where('type', 'synthetic.audience')->pluck('notifiable_id')->all();
        $this->assertContains($matching->id, $recipients);
        $this->assertContains($admin->id, $recipients);
        $this->assertNotContains($other->id, $recipients);
        $this->assertSame(AsyncOutboxEvent::STATUS_PROCESSED, $intent->fresh()->status);
    }

    public function test_worker_processes_persisted_broadcast_and_records_latency(): void
    {
        Event::fake([NotificationSent::class]);
        $user = User::factory()->create();
        $notification = AppNotification::create([
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'type' => 'synthetic',
            'title' => 'Synthetic',
            'body' => 'Synthetic',
        ]);
        $event = AsyncOutboxEvent::create([
            'type' => AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST,
            'aggregate_type' => $notification->getMorphClass(),
            'aggregate_id' => $notification->id,
            'payload' => [],
            'dedupe_key' => 'process-once',
            'status' => AsyncOutboxEvent::STATUS_PENDING,
            'available_at' => now(),
        ]);

        (new ProcessAsyncOutboxEventJob($event->id))->handle(app(NotificationService::class));

        $event->refresh();
        $this->assertSame(AsyncOutboxEvent::STATUS_PROCESSED, $event->status);
        $this->assertNotNull($event->processed_at);
        $this->assertNotNull($event->queue_delay_ms);
        $this->assertNotNull($event->runtime_ms);
        Event::assertDispatched(NotificationSent::class);
    }

    public function test_failure_is_retryable_then_terminal_and_visible(): void
    {
        $event = AsyncOutboxEvent::create([
            'type' => 'unsupported.synthetic',
            'aggregate_type' => User::class,
            'aggregate_id' => 1,
            'payload' => [],
            'dedupe_key' => 'unsupported-1',
            'status' => AsyncOutboxEvent::STATUS_PENDING,
            'available_at' => now(),
        ]);
        $job = new ProcessAsyncOutboxEventJob($event->id);

        try {
            $job->handle(app(NotificationService::class));
            $this->fail('Unsupported outbox types must fail visibly.');
        } catch (RuntimeException) {
            $this->assertSame(AsyncOutboxEvent::STATUS_RETRYING, $event->fresh()->status);
        }

        $job->failed(new RuntimeException('exhausted'));
        $this->assertSame(AsyncOutboxEvent::STATUS_FAILED, $event->fresh()->status);
        $this->assertNotNull($event->fresh()->failed_at);
    }

    public function test_reverb_transport_failure_keeps_broadcast_visible_for_retry(): void
    {
        $user = User::factory()->create();
        $notification = AppNotification::create([
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'type' => 'synthetic',
            'title' => 'Synthetic',
            'body' => 'Synthetic',
        ]);
        $event = AsyncOutboxEvent::create([
            'type' => AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST,
            'aggregate_type' => $notification->getMorphClass(),
            'aggregate_id' => $notification->id,
            'payload' => [],
            'dedupe_key' => 'reverb-offline',
            'status' => AsyncOutboxEvent::STATUS_PENDING,
            'available_at' => now(),
        ]);
        $broadcaster = $this->mock(BroadcastFactory::class);
        $broadcaster->shouldReceive('event')->once()->andThrow(new RuntimeException('synthetic Reverb outage'));

        try {
            (new ProcessAsyncOutboxEventJob($event->id))->handle(app(NotificationService::class));
            $this->fail('A Reverb transport failure must be retried by the queue.');
        } catch (RuntimeException) {
            $event->refresh();
            $this->assertSame(AsyncOutboxEvent::STATUS_RETRYING, $event->status);
            $this->assertSame('RuntimeException', $event->last_error);
            $this->assertGreaterThan($event->created_at, $event->available_at);
        }
    }

    public function test_dispatcher_recovers_stale_processing_but_requires_flag_for_terminal_failure(): void
    {
        Queue::fake();
        $stale = $this->outboxRow('stale', AsyncOutboxEvent::STATUS_PROCESSING, now()->subMinutes(5));
        $failed = $this->outboxRow('terminal', AsyncOutboxEvent::STATUS_FAILED);

        $this->artisan('outbox:dispatch')->assertSuccessful();
        Queue::assertPushed(ProcessAsyncOutboxEventJob::class, fn ($job) => $job->outboxEventId === $stale->id);
        Queue::assertNotPushed(ProcessAsyncOutboxEventJob::class, fn ($job) => $job->outboxEventId === $failed->id);

        Queue::fake();
        $this->artisan('outbox:dispatch', ['--include-failed' => true])->assertSuccessful();
        Queue::assertPushed(ProcessAsyncOutboxEventJob::class, fn ($job) => $job->outboxEventId === $failed->id);
    }

    private function outboxRow(string $key, string $status, $startedAt = null): AsyncOutboxEvent
    {
        return AsyncOutboxEvent::create([
            'type' => AsyncOutboxService::TYPE_NOTIFICATION_BROADCAST,
            'aggregate_type' => AppNotification::class,
            'aggregate_id' => 1,
            'payload' => [],
            'dedupe_key' => $key,
            'status' => $status,
            'available_at' => now(),
            'started_at' => $startedAt,
        ]);
    }
}
