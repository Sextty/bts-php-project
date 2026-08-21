<?php

namespace Tests\Feature\Broadcasting;

use App\Broadcasting\AdminChannel;
use App\Broadcasting\ApplicationChannel;
use App\Broadcasting\StaffChannel;
use App\Broadcasting\UserChannel;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CreditApplication\CreditApplicationTestCase;

/**
 * Server-side authorization for the notification/application Reverb channels, exercised over
 * the real /broadcasting/auth endpoint with the pusher driver (which performs the whole access
 * decision locally — denied → 403, granted → signed response, no network). The handlers are
 * registered on the default connection at boot; re-registering the same classes here on the
 * pusher connection is what makes this run the real gate.
 */
class ChannelAuthorizationTest extends CreditApplicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'cluster' => 'mt1',
                    'host' => 'api-mt1.pusher.com',
                    'port' => 443,
                    'scheme' => 'https',
                    'useTLS' => true,
                ],
                'client_options' => [],
            ],
        ]);

        Broadcast::channel('user.{id}', UserChannel::class);
        Broadcast::channel('application.{applicationId}', ApplicationChannel::class);
        Broadcast::channel('staff', StaffChannel::class);
        Broadcast::channel('admin', AdminChannel::class);
    }

    private function as(mixed $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function subscribe(string $channel): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => Str::startsWith($channel, 'private-') ? $channel : 'private-'.$channel,
            'socket_id' => '1234567890.123456789',
        ]);
    }

    private function submittedApplication(string $ville = 'Tunis'): CreditApplication
    {
        $this->as($this->user);

        $application = $this->newApplication();
        $this->putJson("/api/applications/{$application->id}/client", $this->validClientPayload());
        $this->putJson("/api/applications/{$application->id}/credit", $this->validCreditRequestPayload());

        $project = $this->validProjectPayload();
        $project['ville'] = $ville;
        $this->putJson("/api/applications/{$application->id}/project", $project);

        $this->postJson("/api/applications/{$application->id}/documents", [
            'document_type' => 'cin',
            'file' => UploadedFile::fake()->create('cin.pdf', 500, 'application/pdf'),
        ]);
        $this->postJson("/api/applications/{$application->id}/validation-1");
        $this->postJson("/api/applications/{$application->id}/validation-2");

        return $application->fresh();
    }

    // ---- private-user.{id} -------------------------------------------------------------

    public function test_user_channel_is_owned_by_the_user(): void
    {
        $this->as($this->user);
        $this->subscribe("private-user.{$this->user->id}")->assertOk();

        $this->as(User::factory()->create());
        $this->subscribe("private-user.{$this->user->id}")->assertForbidden();
    }

    // ---- private-application.{applicationId} -------------------------------------------

    public function test_application_channel_requires_ownership_or_branch_access(): void
    {
        $tunis = Branch::factory()->default()->create(['ville' => 'Tunis']);
        $sfax = Branch::factory()->create(['ville' => 'Sfax']);

        $appTunis = $this->submittedApplication('Tunis');
        $appSfax = $this->submittedApplication('Sfax');

        // Owner subscribes to their own application.
        $this->as($this->user);
        $this->subscribe("private-application.{$appTunis->id}")->assertOk();

        // Another customer — denied, same IDOR rule as the HTTP routes.
        $this->as(User::factory()->create());
        $this->subscribe("private-application.{$appTunis->id}")->assertForbidden();

        // Branch-assigned staff only reach their branch's applications.
        $this->as(StaffUser::factory()->create(['role' => 'staff', 'branch_id' => $tunis->id]));
        $this->subscribe("private-application.{$appTunis->id}")->assertOk();
        $this->subscribe("private-application.{$appSfax->id}")->assertForbidden();

        // Admins are never branch-restricted.
        $this->as(StaffUser::factory()->admin()->create());
        $this->subscribe("private-application.{$appSfax->id}")->assertOk();

        // Suspended staff subscribe nowhere.
        $this->as(StaffUser::factory()->create(['status' => 'suspended']));
        $this->subscribe("private-application.{$appTunis->id}")->assertForbidden();

        // A guessed application id that does not exist is refused, not silently accepted.
        $this->as($this->user);
        $this->subscribe('private-application.999999')->assertForbidden();
    }

    // ---- private-staff -----------------------------------------------------------------

    public function test_staff_channel_is_open_to_active_staff_only(): void
    {
        $this->as(StaffUser::factory()->create());
        $this->subscribe('private-staff')->assertOk();

        $this->as(StaffUser::factory()->create(['status' => 'suspended']));
        $this->subscribe('private-staff')->assertForbidden();

        // Customers never reach the staff channel.
        $this->as($this->user);
        $this->subscribe('private-staff')->assertForbidden();
    }

    // ---- private-admin -----------------------------------------------------------------

    public function test_admin_channel_is_open_to_admin_rank_and_above_only(): void
    {
        $this->as(StaffUser::factory()->admin()->create());
        $this->subscribe('private-admin')->assertOk();

        $this->as(StaffUser::factory()->create());
        $this->subscribe('private-admin')->assertForbidden();

        $this->as(StaffUser::factory()->create(['role' => 'super_admin']));
        $this->subscribe('private-admin')->assertOk();
    }
}
