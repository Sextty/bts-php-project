<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\Appointment;
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
        private readonly CreditApplicationStateMachine $stateMachine,
        private readonly BranchMatchingService $branchMatching,
        private readonly NotificationService $notifications,
    ) {}

    public function create(User $user, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        return DB::transaction(function () use ($user, $ip, $userAgent) {
            $application = CreditApplication::query()->forceCreate([
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
        }, 5);
    }

    /**
     * Removes an unfinished dossier from normal queries while retaining its records, documents,
     * and audit history for traceability. Validated and terminal dossiers are never deletable by
     * the customer.
     */
    public function deleteUnfinished(CreditApplication $application, User $actor, ?string $ip = null, ?string $userAgent = null): void
    {
        DB::transaction(function () use ($application, $actor, $ip, $userAgent) {
            $lockedApplication = CreditApplication::query()
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if (! $lockedApplication->canBeDeletedByCustomer()) {
                throw new ApiException(ApiErrorCode::ApplicationCannotBeDeleted);
            }

            $this->auditLog->log(
                'credit_application.deleted_by_customer',
                $actor,
                previousState: ['status' => $lockedApplication->status],
                newState: ['deleted' => true],
                ipAddress: $ip,
                userAgent: $userAgent,
                application: $lockedApplication,
            );

            $lockedApplication->delete();
        }, 5);
    }

    /**
     * Throws 403 if the application can no longer be edited by the customer. Called at the top
     * of every mutating action (client/credit/project PUT, document upload/delete) — the
     * frontend hiding edit controls after lock is a UX nicety, not the enforcement.
     */
    public function assertEditable(CreditApplication $application): void
    {
        if ($application->isLocked()) {
            throw new ApiException(ApiErrorCode::ApplicationLocked);
        }
    }

    public function saveClient(CreditApplication $application, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): Client
    {
        $this->assertEditable($application);

        // The customer never supplies code_client — it's assigned once, on first save of this
        // step, and never regenerated on subsequent edits. Same pattern as n_demande on Étape 2.
        return DB::transaction(function () use ($application, $data, $actor, $ip, $userAgent) {
            $existing = $application->client;

            if (! $existing || ! $existing->code_client) {
                $data['code_client'] = $this->numberService->generate('CL');
            }

            $client = $application->client()->updateOrCreate(['credit_application_id' => $application->id], $data);

            $this->bumpStatus($application, CreditApplication::STATUS_STEP_1_COMPLETED, $actor, $ip, $userAgent);

            $this->auditLog->log('credit_application.client_saved', $application->user, newState: ['step' => 1], ipAddress: $ip, userAgent: $userAgent, application: $application);

            return $client;
        }, 5);
    }

    public function saveCreditRequest(CreditApplication $application, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): CreditRequest
    {
        $this->assertEditable($application);
        $this->assertPreviousStepCompleted($application, CreditApplication::STATUS_STEP_1_COMPLETED);

        // The customer never supplies n_demande or identifiant_personne — n_demande is
        // server-generated (ApplicationNumberService), and identifiant_personne is derived from
        // the client's code_client. Any client-supplied values for these fields are ignored.
        return DB::transaction(function () use ($application, $data, $actor, $ip, $userAgent) {
            $existing = $application->creditRequest;

            if (! $existing || ! $existing->n_demande) {
                $data['n_demande'] = $this->numberService->generate();
            }

            // nom_ou_rs and prenom_ou_dc must always match the client's canonical nom/prénom
            // established in Step 1. Any client-supplied values for these fields are ignored.
            $data['nom_ou_rs'] = $application->client?->nom ?? $data['nom_ou_rs'] ?? null;
            $data['prenom_ou_dc'] = $application->client?->prenom ?? $data['prenom_ou_dc'] ?? null;

            // identifiant_personne is always derived from the client's code_client — the
            // customer must never supply or override it.
            $data['identifiant_personne'] = $application->client?->code_client ?? $data['identifiant_personne'] ?? null;

            $creditRequest = $application->creditRequest()->updateOrCreate(['credit_application_id' => $application->id], $data);

            $this->bumpStatus($application, CreditApplication::STATUS_STEP_2_COMPLETED, $actor, $ip, $userAgent);

            $this->auditLog->log('credit_application.credit_request_saved', $application->user, newState: ['step' => 2, 'n_demande' => $creditRequest->n_demande], ipAddress: $ip, userAgent: $userAgent, application: $application);

            return $creditRequest;
        }, 5);
    }

    public function saveProject(CreditApplication $application, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): Project
    {
        $this->assertEditable($application);
        $this->assertPreviousStepCompleted($application, CreditApplication::STATUS_STEP_2_COMPLETED);

        // code_projet is server-generated on first save and never regenerated on subsequent
        // edits. identifiant_personne is derived from the client's code_client. Any
        // client-supplied values for these fields are ignored.
        return DB::transaction(function () use ($application, $data, $actor, $ip, $userAgent) {
            $existing = $application->project;

            if (! $existing || ! $existing->code_projet) {
                $data['code_projet'] = $this->numberService->generate('PJ');
            }

            // nom_ou_rs and prenom_ou_dc must always match the client's canonical nom/prénom
            // established in Step 1. Any client-supplied values for these fields are ignored.
            $data['nom_ou_rs'] = $application->client?->nom ?? $data['nom_ou_rs'] ?? null;
            $data['prenom_ou_dc'] = $application->client?->prenom ?? $data['prenom_ou_dc'] ?? null;

            // identifiant_personne is always derived from the client's code_client — the
            // customer must never supply or override it.
            $data['identifiant_personne'] = $application->client?->code_client ?? $data['identifiant_personne'] ?? null;

            if (empty($data['localisation'])) {
                $data['localisation'] = $data['delegation'] ?? $data['ville'] ?? 'Tunisie';
            }
            if (! isset($data['financement']) || $data['financement'] === '' || $data['financement'] === null) {
                $data['financement'] = max(0, (float) ($data['cout'] ?? 0) - (float) ($data['investissement_personnel'] ?? 0));
            }

            $project = $application->project()->updateOrCreate(['credit_application_id' => $application->id], $data);

            $this->bumpStatus($application, CreditApplication::STATUS_STEP_3_COMPLETED, $actor, $ip, $userAgent);
            $this->bumpStatus($application, CreditApplication::STATUS_READY_FOR_VALIDATION_1, $actor, $ip, $userAgent);

            $this->auditLog->log('credit_application.project_saved', $application->user, newState: ['step' => 3, 'code_projet' => $project->code_projet], ipAddress: $ip, userAgent: $userAgent, application: $application);

            return $project;
        }, 5);
    }

    /**
     * Validation-2 is the customer's final confirmation. Per the spec, "After Validation 2:
     * FINAL LOCK" describes no separate action in between — so this method performs all three
     * transitions atomically: VALIDATION_2 → FINAL_LOCKED → SUBMITTED, audit-logs each one,
     * and auto-submits the application so it immediately enters the staff review pipeline.
     */
    public function confirmValidationTwoAndLock(CreditApplication $application, User $actor, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        if ($application->status !== CreditApplication::STATUS_VALIDATION_1_COMPLETED) {
            throw new ApiException(ApiErrorCode::Validation1Required);
        }

        DB::transaction(function () use ($application, $actor, $ip, $userAgent) {
            $this->stateMachine->apply($application, CreditApplication::STATUS_VALIDATION_2, $actor, ip: $ip, userAgent: $userAgent);
            $this->auditLog->log('credit_application.validation_2_confirmed', $application->user, previousState: ['status' => CreditApplication::STATUS_VALIDATION_1_COMPLETED], newState: ['status' => CreditApplication::STATUS_VALIDATION_2], ipAddress: $ip, userAgent: $userAgent, application: $application);

            $this->stateMachine->apply($application, CreditApplication::STATUS_FINAL_LOCKED, $actor, ip: $ip, userAgent: $userAgent);
            $this->auditLog->log('credit_application.final_locked', $application->user, previousState: ['status' => CreditApplication::STATUS_VALIDATION_2], newState: ['status' => CreditApplication::STATUS_FINAL_LOCKED], ipAddress: $ip, userAgent: $userAgent, application: $application);

            $this->submitCore($application, $actor, $ip, $userAgent);
        }, 5);

        return $application->fresh();
    }

    public function submit(CreditApplication $application, User $actor, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        if ($application->status !== CreditApplication::STATUS_FINAL_LOCKED) {
            throw new ApiException(ApiErrorCode::ApplicationNotLocked);
        }

        return DB::transaction(function () use ($application, $actor, $ip, $userAgent) {
            $this->submitCore($application, $actor, $ip, $userAgent);

            return $application->fresh();
        });
    }

    /**
     * Core submission logic shared by submit() (explicit customer action) and
     * confirmValidationTwoAndLock() (auto-submit after validation-2). Must be called
     * inside an existing DB transaction — never starts its own.
     *
     * Sets submitted_at, routes to a branch, transitions FINAL_LOCKED → SUBMITTED,
     * audit-logs the submission, and notifies staff.
     */
    private function submitCore(CreditApplication $application, User $actor, ?string $ip = null, ?string $userAgent = null): void
    {
        $attributes = ['submitted_at' => now()];

        // Route the application to its branch the moment it enters the staff pipeline, so
        // branch-isolated staff can be scoped with a plain column comparison. Best-effort: when
        // no branch is configured the application stays unassigned and is visible only to global
        // security/superuser roles; adminApprove still surfaces NO_BRANCH_AVAILABLE as before.
        try {
            $attributes['branch_id'] = $this->branchMatching->findForApplication($application)->id;
        } catch (ApiException $e) {
            if ($e->errorCode !== ApiErrorCode::NoBranchAvailable->value) {
                throw $e;
            }
        }

        $this->stateMachine->apply(
            $application,
            CreditApplication::STATUS_SUBMITTED,
            $actor,
            attributes: $attributes,
            ip: $ip,
            userAgent: $userAgent,
        );

        $this->auditLog->log('credit_application.submitted', $application->user, previousState: ['status' => CreditApplication::STATUS_FINAL_LOCKED], newState: ['status' => CreditApplication::STATUS_SUBMITTED], ipAddress: $ip, userAgent: $userAgent, application: $application);

        $this->notifications->queueStaffAudience(
            $application,
            'application.submitted',
            'New application submitted',
            'Application '.$application->application_number.' has been submitted and is waiting for review.',
            ['application_id' => $application->id, 'application_number' => $application->application_number, 'branch_id' => $application->branch_id],
            dedupeKey: 'application-submitted-'.$application->id,
        );
    }

    /**
     * Cancels an application the customer still owns the draft zone of (DRAFT up to and
     * including VALIDATION_2 — the state machine rejects anything later). Cancellation is
     * terminal: every transition out of CANCELLED is impossible, and the model's isLocked()
     * already makes a cancelled application read-only to its owner.
     */
    public function cancel(CreditApplication $application, User $actor, ?string $ip = null, ?string $userAgent = null): CreditApplication
    {
        return DB::transaction(function () use ($application, $actor, $ip, $userAgent) {
            $previous = $application->status;

            $this->stateMachine->apply($application, CreditApplication::STATUS_CANCELLED, $actor, ip: $ip, userAgent: $userAgent);

            // Mark any existing appointments as cancelled so their slots become available immediately.
            $application->appointments()
                ->whereNotIn('status', [Appointment::STATUS_REJECTED, Appointment::STATUS_CANCELLED])
                ->update([
                    'status' => Appointment::STATUS_CANCELLED,
                    'decided_at' => now(),
                ]);

            $this->auditLog->log('credit_application.cancelled', $application->user, previousState: ['status' => $previous], newState: ['status' => CreditApplication::STATUS_CANCELLED], ipAddress: $ip, userAgent: $userAgent, application: $application);

            return $application->fresh();
        });
    }

    /** Moves status forward to $target only if that's further than where it already is. */
    private function bumpStatus(CreditApplication $application, string $target, User $actor, ?string $ip = null, ?string $userAgent = null): void
    {
        if (! $application->hasReached($target)) {
            $this->stateMachine->apply($application, $target, $actor, ip: $ip, userAgent: $userAgent);
        }
    }

    private function assertPreviousStepCompleted(CreditApplication $application, string $requiredStatus): void
    {
        if (! $application->hasReached($requiredStatus)) {
            throw new ApiException(ApiErrorCode::StepsIncomplete);
        }
    }
}
