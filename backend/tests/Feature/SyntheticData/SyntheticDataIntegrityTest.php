<?php

namespace Tests\Feature\SyntheticData;

use App\Models\AppNotification;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\ValidationStep;
use App\Services\SyntheticData\GenerationOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyntheticDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMERS = 20;

    private const APPLICATIONS = 25;

    private const CHUNK_SIZE = 10;

    private const SEED = 'integrity-seed-2026';

    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        CreditApplication::STATUS_DRAFT => [CreditApplication::STATUS_STEP_1_COMPLETED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_STEP_1_COMPLETED => [CreditApplication::STATUS_STEP_2_COMPLETED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_STEP_2_COMPLETED => [CreditApplication::STATUS_STEP_3_COMPLETED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_STEP_3_COMPLETED => [CreditApplication::STATUS_READY_FOR_VALIDATION_1, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_READY_FOR_VALIDATION_1 => [CreditApplication::STATUS_VALIDATION_1_COMPLETED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_VALIDATION_1_COMPLETED => [CreditApplication::STATUS_VALIDATION_2, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_VALIDATION_2 => [CreditApplication::STATUS_FINAL_LOCKED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_FINAL_LOCKED => [CreditApplication::STATUS_SUBMITTED],
        CreditApplication::STATUS_SUBMITTED => [CreditApplication::STATUS_STAFF_APPROVED, CreditApplication::STATUS_STAFF_REJECTED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_STAFF_APPROVED => [CreditApplication::STATUS_APPROVED, CreditApplication::STATUS_REJECTED, CreditApplication::STATUS_APPOINTMENT_PROPOSED, CreditApplication::STATUS_APPOINTMENT_CONFIRMED, CreditApplication::STATUS_APPOINTMENT_LOCKED, CreditApplication::STATUS_CANCELLED],
        CreditApplication::STATUS_APPROVED => [CreditApplication::STATUS_APPOINTMENT_PROPOSED, CreditApplication::STATUS_APPOINTMENT_CONFIRMED, CreditApplication::STATUS_APPOINTMENT_LOCKED],
        CreditApplication::STATUS_APPOINTMENT_PROPOSED => [CreditApplication::STATUS_APPOINTMENT_PROPOSED, CreditApplication::STATUS_APPOINTMENT_CONFIRMED, CreditApplication::STATUS_APPOINTMENT_LOCKED],
        CreditApplication::STATUS_APPOINTMENT_CONFIRMED => [CreditApplication::STATUS_APPOINTMENT_CONFIRMED, CreditApplication::STATUS_APPOINTMENT_PROPOSED],
        CreditApplication::STATUS_APPOINTMENT_LOCKED => [CreditApplication::STATUS_APPOINTMENT_CONFIRMED, CreditApplication::STATUS_APPOINTMENT_PROPOSED],
        CreditApplication::STATUS_STAFF_REJECTED => [],
        CreditApplication::STATUS_REJECTED => [],
        CreditApplication::STATUS_CANCELLED => [],
    ];

    public function test_generator_preserves_integrity_business_rules_and_is_idempotent(): void
    {
        [$branchA, $branchB] = $this->createBranches();
        $existingUser = User::factory()->create([
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'existing.customer@example.test',
            'phone' => '+21629999999',
            'status' => 'active',
        ]);
        $preservedColumns = ['first_name', 'last_name', 'email', 'phone', 'status', 'created_at', 'updated_at'];
        $existingSnapshot = array_intersect_key($existingUser->getRawOriginal(), array_flip($preservedColumns));

        $options = $this->generationOptions();
        $command = $this->commandArguments();

        $this->artisan('bts:generate-data', $command)->assertSuccessful();

        $syntheticUsers = User::query()
            ->where('email', 'like', $this->emailPattern($options))
            ->orderBy('id')
            ->get();
        $this->assertCount(self::CUSTOMERS, $syntheticUsers);

        $applications = CreditApplication::query()
            ->whereIn('user_id', $syntheticUsers->modelKeys())
            ->with([
                'user',
                'client',
                'creditRequest',
                'project',
                'documents',
                'validationSteps',
                'appointments.branch',
                'reportMessages.staffUser',
            ])
            ->orderBy('id')
            ->get();
        $this->assertCount(self::APPLICATIONS, $applications);

        $existingUser->refresh();
        $this->assertEquals(
            $existingSnapshot,
            array_intersect_key($existingUser->getRawOriginal(), array_flip($preservedColumns)),
        );

        $this->assertNoForeignKeyOrPolymorphicOrphans();
        $this->assertApplicationInvariants($applications);
        $this->assertAppointmentInvariants($applications);
        $this->assertReportSenderInvariants($applications);
        $this->assertBranchIsolation($applications, $branchA, $branchB, $options);

        $countsBeforeRerun = $this->relevantTableCounts();
        $this->artisan('bts:generate-data', $command)->assertSuccessful();

        $this->assertSame($countsBeforeRerun, $this->relevantTableCounts());
        $this->assertSame(
            self::CUSTOMERS,
            User::query()->where('email', 'like', $this->emailPattern($options))->count(),
        );
        $this->assertSame(
            self::APPLICATIONS,
            CreditApplication::query()->whereIn('user_id', $syntheticUsers->modelKeys())->count(),
        );
    }

    public function test_command_refuses_production_without_all_safety_factors(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->detectEnvironment(static fn (): string => 'production');
        config([
            'synthetic_data.production.enabled' => false,
            'synthetic_data.production.token' => '',
        ]);

        try {
            $this->artisan('bts:generate-data', $this->commandArguments())->assertFailed();

            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('staff_users', 0);
            $this->assertDatabaseCount('credit_applications', 0);
        } finally {
            $this->app->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    /** @return array{Branch, Branch} */
    private function createBranches(): array
    {
        $branchA = Branch::factory()->default()->create([
            'name' => 'BTS Agence Synthétique Tunis',
            'ville' => 'Tunis Test',
            'delegation' => 'Délégation Test A',
            'address' => 'Adresse synthétique A',
            'phone' => '+21670000001',
            'latitude' => 36.8065000,
            'longitude' => 10.1815000,
            'daily_capacity' => 4,
            'slot_start_time' => '09:00:00',
            'slot_end_time' => '16:00:00',
        ]);
        $branchB = Branch::factory()->create([
            'name' => 'BTS Agence Synthétique Sfax',
            'ville' => 'Sfax Test',
            'delegation' => 'Délégation Test B',
            'address' => 'Adresse synthétique B',
            'phone' => '+21670000002',
            'latitude' => 34.7406000,
            'longitude' => 10.7603000,
            'daily_capacity' => 4,
            'slot_start_time' => '09:00:00',
            'slot_end_time' => '16:00:00',
        ]);

        return [$branchA, $branchB];
    }

    private function generationOptions(): GenerationOptions
    {
        return GenerationOptions::fromInput(
            profile: 'small',
            customers: self::CUSTOMERS,
            applications: self::APPLICATIONS,
            seed: self::SEED,
            chunk: self::CHUNK_SIZE,
            materializeDocuments: false,
        );
    }

    /** @return array<string, int|string|bool> */
    private function commandArguments(): array
    {
        return [
            '--profile' => 'small',
            '--customers' => self::CUSTOMERS,
            '--applications' => self::APPLICATIONS,
            '--seed' => self::SEED,
            '--chunk' => self::CHUNK_SIZE,
            '--metadata-only-documents' => true,
        ];
    }

    private function emailPattern(GenerationOptions $options): string
    {
        return $options->emailPrefix().'%@'.config('synthetic_data.email_domain');
    }

    private function assertNoForeignKeyOrPolymorphicOrphans(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        }

        $notifications = AppNotification::query()->get(['notifiable_type', 'notifiable_id']);
        foreach ($notifications as $notification) {
            $this->assertSame(User::class, $notification->notifiable_type);
            $this->assertTrue(User::withTrashed()->whereKey($notification->notifiable_id)->exists());
        }
    }

    /** @param Collection<int, CreditApplication> $applications */
    private function assertApplicationInvariants(Collection $applications): void
    {
        foreach ($applications as $application) {
            $path = $this->auditedStatePath($application);
            $this->assertSame($application->status, $path[array_key_last($path)]);

            foreach (array_slice($path, 1) as $index => $next) {
                $previous = $path[$index];
                $this->assertContains($next, self::ALLOWED_TRANSITIONS[$previous] ?? []);
            }

            $hasClient = in_array(CreditApplication::STATUS_STEP_1_COMPLETED, $path, true);
            $hasCredit = in_array(CreditApplication::STATUS_STEP_2_COMPLETED, $path, true);
            $hasProject = in_array(CreditApplication::STATUS_STEP_3_COMPLETED, $path, true);
            $hasPassedValidation = in_array(CreditApplication::STATUS_VALIDATION_1_COMPLETED, $path, true);
            $hasSubmitted = in_array(CreditApplication::STATUS_SUBMITTED, $path, true);

            $this->assertSame($hasClient, $application->client !== null);
            $this->assertSame($hasCredit, $application->creditRequest !== null);
            $this->assertSame($hasProject, $application->project !== null);
            $this->assertSame($hasSubmitted, $application->branch_id !== null);
            $this->assertSame($hasSubmitted, $application->submitted_at !== null);

            if ($application->client !== null) {
                $this->assertSame($application->user->first_name, $application->client->prenom);
                $this->assertSame($application->user->last_name, $application->client->nom);
            }

            if ($application->creditRequest !== null) {
                $client = $application->client;
                $credit = $application->creditRequest;
                $this->assertNotNull($client);
                $this->assertSame($client->code_client, $credit->identifiant_personne);
                $this->assertSame($client->nom, $credit->nom_ou_rs);
                $this->assertSame($client->prenom, $credit->prenom_ou_dc);
                $this->assertSame($client->type_pid, $credit->type_pid);
                $this->assertSame($client->numero_pid, $credit->numero_pid);
                $this->assertSame(
                    $this->millimes($credit->montant_global_sollicite),
                    $this->millimes($credit->montant_eqp)
                        + $this->millimes($credit->montant_fdr)
                        + $this->millimes($credit->montant_amg)
                        + $this->millimes($credit->montant_chp),
                );
            }

            if ($application->project !== null) {
                $client = $application->client;
                $project = $application->project;
                $this->assertNotNull($client);
                $this->assertSame($client->code_client, $project->identifiant_personne);
                $this->assertSame($client->nom, $project->nom_ou_rs);
                $this->assertSame($client->prenom, $project->prenom_ou_dc);
                $this->assertSame(
                    $this->millimes($project->cout),
                    $this->millimes($project->financement) + $this->millimes($project->investissement_personnel),
                );
                $this->assertSame(
                    $this->millimes($application->creditRequest->montant_global_sollicite),
                    $this->millimes($project->financement),
                );
            }

            if ($hasPassedValidation) {
                $passed = $application->validationSteps->contains(
                    fn ($step): bool => $step->step === ValidationStep::STEP_VALIDATION_1
                        && $step->status === ValidationStep::STATUS_PASSED,
                );
                $this->assertTrue($passed);
                $this->assertNotEmpty($application->documents);

                $documentTypes = $application->documents->pluck('document_type')->all();
                $identityType = $application->client->type_pid === 'Carte de séjour' ? 'carte_sejour' : 'passport';
                $this->assertContains($identityType, $documentTypes);

                foreach (['eqp', 'fdr', 'amg', 'chp'] as $type) {
                    if ($this->millimes($application->creditRequest->{'montant_'.$type}) > 0) {
                        $this->assertContains($type, $documentTypes);
                    }
                }

                foreach ($application->documents as $document) {
                    $this->assertTrue($document->ai_is_valid);
                    $this->assertSame('verified', $document->ai_processing_status);
                    $this->assertFalse($document->ai_requires_human_review);
                    $this->assertNotNull($document->ai_verified_at);
                }
            }
        }
    }

    /** @return list<string> */
    private function auditedStatePath(CreditApplication $application): array
    {
        $path = [CreditApplication::STATUS_DRAFT];
        $transitions = AuditLog::query()
            ->where('credit_application_id', $application->id)
            ->where('action', 'credit_application.status_changed')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($transitions as $transition) {
            $this->assertSame($path[array_key_last($path)], $transition->previous_state['status'] ?? null);
            $path[] = $transition->new_state['status'];
        }

        return $path;
    }

    /** @param Collection<int, CreditApplication> $applications */
    private function assertAppointmentInvariants(Collection $applications): void
    {
        $appointmentApplications = $applications->filter(fn (CreditApplication $application): bool => $application->appointments->isNotEmpty());
        $this->assertNotEmpty($appointmentApplications);
        $activeSlotKeys = [];
        $activePerBranchDay = [];

        foreach ($appointmentApplications as $application) {
            $this->assertContains($application->status, [
                CreditApplication::STATUS_APPOINTMENT_PROPOSED,
                CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
                CreditApplication::STATUS_APPOINTMENT_LOCKED,
            ]);

            $appointments = $application->appointments->sortBy('attempt_number')->values();
            $this->assertSame(range(1, $appointments->count()), $appointments->pluck('attempt_number')->all());
            $this->assertLessThanOrEqual(Appointment::MAX_ATTEMPTS, $appointments->count());
            $this->assertSame($appointments->count(), $appointments->unique(
                fn (Appointment $appointment): string => $appointment->scheduled_date->toDateString().'|'.$this->normalizeTime($appointment->scheduled_time),
            )->count());

            foreach ($appointments as $index => $appointment) {
                $this->assertSame($application->branch_id, $appointment->branch_id);
                $this->assertTrue($appointment->scheduled_date->isWeekday());
                $this->assertContains(
                    $this->normalizeTime($appointment->scheduled_time),
                    array_map($this->normalizeTime(...), $appointment->branch->slotTimes()),
                );
                if ($index < $appointments->count() - 1) {
                    $this->assertSame(Appointment::STATUS_REJECTED, $appointment->status);
                }

                if (in_array($appointment->status, [Appointment::STATUS_PROPOSED, Appointment::STATUS_ACCEPTED], true)) {
                    $date = $appointment->scheduled_date->toDateString();
                    $key = $appointment->branch_id.'|'.$date.'|'.$this->normalizeTime($appointment->scheduled_time);
                    $this->assertArrayNotHasKey($key, $activeSlotKeys);
                    $activeSlotKeys[$key] = true;
                    $dayKey = $appointment->branch_id.'|'.$date;
                    $activePerBranchDay[$dayKey] = ($activePerBranchDay[$dayKey] ?? 0) + 1;
                    $this->assertLessThanOrEqual($appointment->branch->daily_capacity, $activePerBranchDay[$dayKey]);
                }
            }

            $latest = $appointments->last();
            if ($application->status === CreditApplication::STATUS_APPOINTMENT_PROPOSED) {
                $this->assertSame(Appointment::STATUS_PROPOSED, $latest->status);
            } elseif ($application->status === CreditApplication::STATUS_APPOINTMENT_CONFIRMED) {
                $this->assertSame(Appointment::STATUS_ACCEPTED, $latest->status);
            } else {
                $this->assertCount(Appointment::MAX_ATTEMPTS, $appointments);
                $this->assertTrue($appointments->every(fn (Appointment $appointment): bool => $appointment->status === Appointment::STATUS_REJECTED));
            }
        }
    }

    /** @param Collection<int, CreditApplication> $applications */
    private function assertReportSenderInvariants(Collection $applications): void
    {
        $messages = ReportMessage::query()
            ->whereIn('credit_application_id', $applications->modelKeys())
            ->with(['creditApplication', 'staffUser'])
            ->get();
        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            if ($message->sender_type === ReportMessage::SENDER_CUSTOMER) {
                $this->assertSame($message->creditApplication->user_id, $message->user_id);
                $this->assertNull($message->staff_user_id);

                continue;
            }

            $this->assertSame(ReportMessage::SENDER_STAFF, $message->sender_type);
            $this->assertNull($message->user_id);
            $this->assertNotNull($message->staffUser);
            if ($message->creditApplication->branch_id !== null) {
                $this->assertSame($message->creditApplication->branch_id, $message->staffUser->branch_id);
            } else {
                $this->assertTrue($message->staffUser->isSuperuser());
            }
        }
    }

    /** @param Collection<int, CreditApplication> $applications */
    private function assertBranchIsolation(
        Collection $applications,
        Branch $branchA,
        Branch $branchB,
        GenerationOptions $options,
    ): void {
        $applicationA = $applications->firstWhere('branch_id', $branchA->id);
        $applicationB = $applications->firstWhere('branch_id', $branchB->id);
        $this->assertNotNull($applicationA);
        $this->assertNotNull($applicationB);

        $domain = config('synthetic_data.email_domain');
        $staffA = StaffUser::query()->where('email', 'syn-'.$options->runKey.'-staff-b'.$branchA->id.'@'.$domain)->firstOrFail();
        $staffB = StaffUser::query()->where('email', 'syn-'.$options->runKey.'-staff-b'.$branchB->id.'@'.$domain)->firstOrFail();

        $this->assertTrue($staffA->isBranchRestricted());
        $this->assertTrue($staffA->canAccessApplication($applicationA));
        $this->assertFalse($staffA->canAccessApplication($applicationB));
        $this->assertTrue($staffB->canAccessApplication($applicationB));
        $this->assertFalse($staffB->canAccessApplication($applicationA));

        $visibleBranchIds = CreditApplication::query()
            ->accessibleToStaff($staffA)
            ->whereIn('id', $applications->modelKeys())
            ->pluck('branch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->assertSame([$branchA->id], $visibleBranchIds);
    }

    /** @return array<string, int> */
    private function relevantTableCounts(): array
    {
        $tables = [
            'users',
            'staff_users',
            'credit_applications',
            'clients',
            'credit_requests',
            'projects',
            'documents',
            'validation_steps',
            'appointments',
            'report_messages',
            'audit_logs',
            'app_notifications',
            'otp_codes',
        ];

        return collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        )->all();
    }

    private function millimes(mixed $amount): int
    {
        return (int) round((float) $amount * 1_000);
    }

    private function normalizeTime(string $time): string
    {
        $time = substr($time, 0, 8);

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
