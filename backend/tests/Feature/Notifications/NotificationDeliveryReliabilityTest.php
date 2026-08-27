<?php

namespace Tests\Feature\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Exceptions\Notifications\PermanentNotificationDeliveryException;
use App\Jobs\DeliverNotificationJob;
use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationChannelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TemporaryTestChannel implements NotificationChannelInterface
{
    public static int $failuresRemaining = 0;

    public static int $successfulDeliveries = 0;

    public function canReach(Model $notifiable): bool
    {
        return true;
    }

    public function deliver(Model $notifiable, AppNotification $notification): void
    {
        if (self::$failuresRemaining-- > 0) {
            throw new RuntimeException('temporary provider outage');
        }
        self::$successfulDeliveries++;
    }
}

class PermanentTestChannel implements NotificationChannelInterface
{
    public function canReach(Model $notifiable): bool
    {
        return false;
    }

    public function deliver(Model $notifiable, AppNotification $notification): void
    {
        throw new PermanentNotificationDeliveryException('synthetic permanent failure');
    }
}

class NotificationDeliveryReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TemporaryTestChannel::$failuresRemaining = 0;
        TemporaryTestChannel::$successfulDeliveries = 0;
    }

    public function test_temporary_failure_is_rethrown_then_eventually_succeeds_once(): void
    {
        [$notification, $delivery] = $this->delivery('temporary');
        $registry = new NotificationChannelRegistry(['temporary' => TemporaryTestChannel::class]);
        TemporaryTestChannel::$failuresRemaining = 1;
        $job = new DeliverNotificationJob($notification->id, 'temporary');

        try {
            $job->handle($registry);
            $this->fail('A retryable exception must escape to the queue worker.');
        } catch (RuntimeException) {
            $this->assertSame(NotificationDelivery::STATUS_RETRYING, $delivery->fresh()->status);
        }

        $job->handle($registry);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(1, TemporaryTestChannel::$successfulDeliveries);

        // Duplicate dispatch after success is an idempotent no-op.
        (new DeliverNotificationJob($notification->id, 'temporary'))->handle($registry);
        $this->assertSame(1, TemporaryTestChannel::$successfulDeliveries);
    }

    public function test_permanent_failure_is_visible_and_is_not_marked_delivered(): void
    {
        [$notification, $delivery] = $this->delivery('permanent');
        $job = new DeliverNotificationJob($notification->id, 'permanent');
        $job->handle(new NotificationChannelRegistry(['permanent' => PermanentTestChannel::class]));

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_PERMANENT_FAILED, $delivery->status);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame('PermanentNotificationDeliveryException', $delivery->last_error);
    }

    public function test_exhausted_retry_callback_records_failed_state(): void
    {
        [$notification, $delivery] = $this->delivery('temporary');
        $job = new DeliverNotificationJob($notification->id, 'temporary');
        $job->failed(new RuntimeException('provider remains unavailable'));

        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->failed_at);
    }

    public function test_reconciliation_requeues_unfinished_but_not_delivered_rows(): void
    {
        Queue::fake();
        [$pending] = $this->delivery('temporary');
        [$delivered, $deliveredState] = $this->delivery('temporary');
        $deliveredState->update(['status' => NotificationDelivery::STATUS_DELIVERED, 'delivered_at' => now()]);

        $this->artisan('notifications:reconcile')->assertSuccessful();

        Queue::assertPushed(DeliverNotificationJob::class, fn (DeliverNotificationJob $job) => $job->notificationId === $pending->id);
        Queue::assertNotPushed(DeliverNotificationJob::class, fn (DeliverNotificationJob $job) => $job->notificationId === $delivered->id);
    }

    public function test_job_declares_bounded_retry_policy(): void
    {
        $job = new DeliverNotificationJob(1, 'email');
        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 30, 120], $job->backoff);
        $this->assertSame(30, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_permanent_worker_failure_is_recorded_in_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        [$notification, $delivery] = $this->delivery('permanent');
        $this->app->instance(
            NotificationChannelRegistry::class,
            new NotificationChannelRegistry(['permanent' => PermanentTestChannel::class]),
        );

        DeliverNotificationJob::dispatch($notification->id, 'permanent');
        $this->artisan('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();

        $this->assertSame(NotificationDelivery::STATUS_PERMANENT_FAILED, $delivery->fresh()->status);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /** @return array{AppNotification, NotificationDelivery} */
    private function delivery(string $channel): array
    {
        $user = User::factory()->create();
        $notification = AppNotification::create([
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'type' => 'synthetic.delivery.test',
            'title' => 'Synthetic test',
            'body' => 'Synthetic test body',
        ]);
        $delivery = NotificationDelivery::create([
            'app_notification_id' => $notification->id,
            'channel' => $channel,
            'status' => NotificationDelivery::STATUS_PENDING,
        ]);

        return [$notification, $delivery];
    }
}
