<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\CreditRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns the credit-application state machine (Part 1 spec, section 6). Every transition is
 * decided here, never left to the frontend — controllers call these methods and nothing else
 * writes to `credit_applications.status`.
 */
class CreditApplicationService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly ApplicationNumberService $numberService,
    ) {}

    public function create(User $user, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        $application = CreditApplication::create([
            'user_id' => $user->id,
            'status' => CreditApplication::STATUS_DRAFT,
        ]);

        $this->auditLog->log(
            'credit_application.created',
            $user,
            newState: ['status' => CreditApplication::STATUS_DRAFT],
            ipAddress: $ip,
            userAgent: $userAgent,
            application: $application,
        );

        return $application;
    }

    /**
     * Throws 403 if the application can no longer be edited by the customer. Called at the top
     * of every mutating action (client/credit/project PUT, document upload/delete) — the
     * frontend hiding edit controls after lock is a UX nicety, not the enforcement.
     */
    public function assertEditable(CreditApplication $application): void
    {
        if ($application->isLocked()) {
            throw new ApiException(
                'APPLICATION_LOCKED',
                'This application has been finalized and can no longer be modified.',
                status: 403,
            );
        }
    }

    public function saveClient(CreditApplication $application, array $data, ?string $ip = null, ?string $userAgent = null): Client
    {
        $this->assertEditable($application);

        $client = $application->client()->updateOrCreate(['credit_application_id' => $application->id], $data);

        $this->bumpStatus($application, CreditApplication::STATUS_STEP_1_COMPLETED);

        $this->auditLog->log('credit_application.client_saved', $application->user, newState: ['step' => 1], ipAddress: $ip, userAgent: $userAgent, application: $application);

        return $client;
    }

    public function saveCreditRequest(CreditApplication $application, array $data, ?string $ip = null, ?string $userAgent = null): CreditRequest
    {
        $this->assertEditable($application);

        $existing = $application->creditRequest;

        // The customer never supplies n_demande — it's assigned once, on first save of this
        // step, and never regenerated on subsequent edits.
        if (! $existing || ! $existing->n_demande) {
            $data['n_demande'] = $this->numberService->generate();
        }

        $creditRequest = $application->creditRequest()->updateOrCreate(['credit_application_id' => $application->id], $data);

        $this->bumpStatus($application, CreditApplication::STATUS_STEP_2_COMPLETED);

        $this->auditLog->log('credit_application.credit_request_saved', $application->user, newState: ['step' => 2, 'n_demande' => $creditRequest->n_demande], ipAddress: $ip, userAgent: $userAgent, application: $application);

        return $creditRequest;
    }

    public function saveProject(CreditApplication $application, array $data, ?string $ip = null, ?string $userAgent = null): Project
    {
        $this->assertEditable($application);

        $project = $application->project()->updateOrCreate(['credit_application_id' => $application->id], $data);

        $this->bumpStatus($application, CreditApplication::STATUS_STEP_3_COMPLETED);
        $this->bumpStatus($application, CreditApplication::STATUS_READY_FOR_VALIDATION_1);

        $this->auditLog->log('credit_application.project_saved', $application->user, newState: ['step' => 3], ipAddress: $ip, userAgent: $userAgent, application: $application);

        return $project;
    }

    /**
     * Validation-2 is the customer's final confirmation. Per the spec, "After Validation 2:
     * FINAL LOCK" describes no separate action in between — so this method performs both
     * transitions atomically and audit-logs each one.
     */
    public function confirmValidationTwoAndLock(CreditApplication $application, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        if ($application->status !== CreditApplication::STATUS_VALIDATION_1_COMPLETED) {
            throw new ApiException('VALIDATION_1_REQUIRED', 'Validation 1 must pass before validation 2.', status: 409);
        }

        DB::transaction(function () use ($application, $ip, $userAgent) {
            $application->update(['status' => CreditApplication::STATUS_VALIDATION_2]);
            $this->auditLog->log('credit_application.validation_2_confirmed', $application->user, previousState: ['status' => CreditApplication::STATUS_VALIDATION_1_COMPLETED], newState: ['status' => CreditApplication::STATUS_VALIDATION_2], ipAddress: $ip, userAgent: $userAgent, application: $application);

            $application->update(['status' => CreditApplication::STATUS_FINAL_LOCKED]);
            $this->auditLog->log('credit_application.final_locked', $application->user, previousState: ['status' => CreditApplication::STATUS_VALIDATION_2], newState: ['status' => CreditApplication::STATUS_FINAL_LOCKED], ipAddress: $ip, userAgent: $userAgent, application: $application);
        });

        return $application->fresh();
    }

    public function submit(CreditApplication $application, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        if ($application->status !== CreditApplication::STATUS_FINAL_LOCKED) {
            throw new ApiException('APPLICATION_NOT_LOCKED', 'The application must be finalized before it can be submitted.', status: 409);
        }

        $application->update([
            'status' => CreditApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->auditLog->log('credit_application.submitted', $application->user, previousState: ['status' => CreditApplication::STATUS_FINAL_LOCKED], newState: ['status' => CreditApplication::STATUS_SUBMITTED], ipAddress: $ip, userAgent: $userAgent, application: $application);

        return $application->fresh();
    }

    /** Moves status forward to $target only if that's further than where it already is. */
    private function bumpStatus(CreditApplication $application, string $target): void
    {
        if (! $application->hasReached($target)) {
            $application->update(['status' => $target]);
        }
    }
}
