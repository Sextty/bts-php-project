<?php

namespace Tests\Feature\Queue;

use App\Jobs\DeliverOtpEmailJob;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The email queues are opt-in (services.otp.queue_delivery / services.password_reset.
 * queue_delivery, both off by default — there is no queue worker in development). These tests
 * pin down both halves: the flag off means nothing is queued (current sync behaviour), the flag
 * on means the right job is dispatched with the right payload.
 */
class QueuedEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_email_is_not_queued_by_default(): void
    {
        Queue::fake();
        // The EmailOtpDriver is only in play when the SMS provider is 'email' — the sync
        // path must send inline (never queue) while the flag stays off.
        config(['services.sms.provider' => 'email']);

        $this->postJson('/api/auth/register', [
            'first_name' => 'Karim',
            'last_name' => 'Ben Salah',
            'email' => 'user@example.com',
            'phone' => '+21620000001',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ])->assertStatus(201);

        Queue::assertNothingPushed();
    }

    public function test_otp_email_is_queued_when_the_flag_is_on(): void
    {
        Queue::fake();
        config(['services.otp.queue_delivery' => true]);
        config(['services.sms.provider' => 'email']);

        $this->postJson('/api/auth/register', [
            'first_name' => 'Karim',
            'last_name' => 'Ben Salah',
            'email' => 'user@example.com',
            'phone' => '+21620000001',
            'password' => 'CorrectHorseBattery',
            'password_confirmation' => 'CorrectHorseBattery',
        ])->assertStatus(201);

        Queue::assertPushed(DeliverOtpEmailJob::class, function (DeliverOtpEmailJob $job) {
            // The register controller stores $request->string('email') (a Stringable) on the
            // model, so the comparison must cast explicitly.
            return (string) $job->user->email === 'user@example.com'
                && $job->code !== ''
                && $job->ttlMinutes === (int) config('services.otp.ttl_minutes');
        });
    }

    public function test_password_reset_email_is_not_queued_by_default(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/auth/password/forgot', ['email' => 'known@example.com'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_password_reset_email_is_queued_when_the_flag_is_on(): void
    {
        Queue::fake();
        config(['services.password_reset.queue_delivery' => true]);
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/auth/password/forgot', ['email' => 'known@example.com'])->assertOk();

        Queue::assertPushed(SendPasswordResetEmailJob::class, function (SendPasswordResetEmailJob $job) use ($user) {
            return $job->user->id === $user->id && $job->token !== '';
        });
    }

    public function test_jobs_carrying_authentication_secrets_are_encrypted(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(ShouldBeEncrypted::class, new DeliverOtpEmailJob($user, '123456', 5));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendPasswordResetEmailJob($user, 'reset-secret'));
    }
}
