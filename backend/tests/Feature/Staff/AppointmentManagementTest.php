<?php

namespace Tests\Feature\Staff;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_list_appointments_and_see_summary_stats(): void
    {
        $branch = Branch::factory()->create(['name' => 'Agence BTS Ariana']);
        $staff = StaffUser::factory()->forBranch($branch)->create();

        $user = User::factory()->create(['first_name' => 'Mohamed', 'last_name' => 'Ben Ali', 'phone' => '+21698123456']);
        $app = CreditApplication::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
        ]);
        Client::create([
            'credit_application_id' => $app->id,
            'nom' => 'Ben Ali',
            'prenom' => 'Mohamed',
            'numero_pid' => '08765432',
        ]);

        Appointment::create([
            'credit_application_id' => $app->id,
            'branch_id' => $branch->id,
            'attempt_number' => 1,
            'scheduled_date' => now()->addDays(2)->format('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'status' => Appointment::STATUS_ACCEPTED,
        ]);

        $token = $staff->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/staff/appointments');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.total', 1)
            ->assertJsonPath('data.stats.accepted', 1)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'appointments' => [
                        '*' => [
                            'id',
                            'scheduled_date',
                            'scheduled_time',
                            'status',
                            'client' => ['name', 'phone', 'cin'],
                            'branch' => ['name', 'address'],
                            'application' => ['id', 'status'],
                        ],
                    ],
                    'stats' => ['total', 'accepted', 'proposed', 'rejected', 'today', 'upcoming'],
                    'meta' => ['current_page', 'last_page', 'total'],
                ],
            ]);
    }

    public function test_staff_can_get_branches_overview_with_metrics(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Agence BTS Sousse',
            'address' => 'Avenue Habib Bourguiba, Sousse',
            'phone' => '73222333',
            'fax' => '73222444',
        ]);
        $otherBranch = Branch::factory()->create(['name' => 'Agence BTS Hors périmètre']);
        $staff = StaffUser::factory()->forBranch($branch)->create();

        $app = CreditApplication::factory()->create(['branch_id' => $branch->id]);
        Appointment::create([
            'credit_application_id' => $app->id,
            'branch_id' => $branch->id,
            'attempt_number' => 1,
            'scheduled_date' => now()->format('Y-m-d'),
            'scheduled_time' => '11:00:00',
            'status' => Appointment::STATUS_ACCEPTED,
        ]);

        $token = $staff->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/staff/branches/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_branches', 1)
            ->assertJsonPath('data.branches.0.id', $branch->id)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'branches' => [
                        '*' => [
                            'id',
                            'name',
                            'address',
                            'phone',
                            'fax',
                            'daily_capacity',
                            'slot_times',
                            'appointments_count',
                            'accepted_appointments_count',
                        ],
                    ],
                    'total_branches',
                ],
            ]);
        $this->assertNotSame($otherBranch->id, $response->json('data.branches.0.id'));
    }

    public function test_staff_appointments_exclude_other_branch_records(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $staff = StaffUser::factory()->forBranch($branch)->create();

        foreach ([$branch, $otherBranch] as $appointmentBranch) {
            $application = CreditApplication::factory()->create(['branch_id' => $appointmentBranch->id]);
            Appointment::create([
                'credit_application_id' => $application->id,
                'branch_id' => $appointmentBranch->id,
                'attempt_number' => 1,
                'scheduled_date' => now()->addDays(3)->toDateString(),
                'scheduled_time' => '14:00:00',
                'status' => Appointment::STATUS_ACCEPTED,
            ]);
        }

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/staff/appointments')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.stats.total', 1)
            ->assertJsonPath('data.appointments.0.branch.id', $branch->id);
    }

    public function test_customer_token_cannot_access_staff_appointments(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/staff/appointments');

        $response->assertForbidden();
    }
}
