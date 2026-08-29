<?php

namespace App\Services\SyntheticData;

use App\Models\Appointment;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\User;
use App\Models\ValidationStep;
use Carbon\CarbonImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class SyntheticRecordFactory
{
    private string $userMorphClass;

    private const MALE_FIRST_NAMES = ['Amine', 'Bilel', 'Fares', 'Hatem', 'Ilyes', 'Karim', 'Malek', 'Nader', 'Oussama', 'Sami', 'Walid', 'Yassine'];

    private const FEMALE_FIRST_NAMES = ['Amani', 'Asma', 'Emna', 'Ines', 'Lina', 'Mariem', 'Nour', 'Rania', 'Rim', 'Sabrine', 'Sarra', 'Yosra'];

    private const LAST_NAMES = ['Ayari', 'Ben Amor', 'Ben Salem', 'Bouazizi', 'Chaabane', 'Dridi', 'Gharbi', 'Hamdi', 'Jebali', 'Mansouri', 'Mejri', 'Trabelsi'];

    private const PROFESSIONS = ['Artisan', 'Commerçant', 'Développeur', 'Électricien', 'Graphiste', 'Mécanicien', 'Menuisier', 'Pâtissier', 'Technicien'];

    private const ACTIVITIES = ['Artisanat', 'Commerce', 'Industrie légère', 'Services numériques', 'Transport', 'Agriculture durable'];

    private const PROJECT_TYPES = ['création', 'extension', 'modernisation'];

    private const PROJECT_OBJECTS = ['Acquisition de matériel', 'Aménagement du local', 'Développement de l’activité', 'Financement du fonds de roulement'];

    public function __construct(
        private readonly GenerationOptions $options,
        private readonly SyntheticStatePath $statePath,
    ) {
        $this->userMorphClass = (new User)->getMorphClass();
    }

    /** @return array{row:array<string,mixed>,first_name:string,last_name:string,civilite:string} */
    public function user(int $ordinal, string $passwordHash, ?int $banningAdminId): array
    {
        $key = 'c'.$ordinal;
        $female = $this->chance('gender', $key, 50);
        $firstName = $this->pick($female ? self::FEMALE_FIRST_NAMES : self::MALE_FIRST_NAMES, 'first-name', $key);
        $lastName = $this->pick(self::LAST_NAMES, 'last-name', $key);
        $suspended = $this->chance('suspension', $key, 2);
        $createdAt = $this->options->anchor()
            ->subYears($this->options->years + 1)
            ->subDays($this->integer('user-created', $key, 0, 365))
            ->setTime($this->integer('user-hour', $key, 7, 20), $this->integer('user-minute', $key, 0, 59));

        $phoneNamespace = (int) (hexdec(substr($this->options->runKey, 0, 5)) % 100_000);

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'civilite' => $female ? $this->pick(['Mme', 'Mlle'], 'civilite', $key) : 'M',
            'row' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $this->options->emailFor($ordinal),
                'phone' => sprintf('+216%05d%07d', $phoneNamespace, $ordinal),
                'phone_verified_at' => $createdAt->addMinutes(5)->format('Y-m-d H:i:s'),
                'password' => $passwordHash,
                'google_id' => null,
                'auth_provider' => 'password',
                'status' => $suspended ? 'suspended' : 'active',
                'banned_at' => $suspended ? $createdAt->addMonths(6)->format('Y-m-d H:i:s') : null,
                'banned_reason' => $suspended ? 'Suspension synthétique destinée aux tests de sécurité.' : null,
                'banned_by_staff_id' => $suspended ? $banningAdminId : null,
                'remember_token' => null,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $createdAt->format('Y-m-d H:i:s'),
                'deleted_at' => null,
            ],
        ];
    }

    /**
     * @param  array{row:array<string,mixed>,first_name:string,last_name:string,civilite:string}  $user
     * @param  list<array<string,mixed>>  $branches
     * @param  array<int, int>  $staffByBranch
     * @return array<string,mixed>
     */
    public function application(
        int $customerOrdinal,
        int $historyIndex,
        int $historyCount,
        int $userId,
        array $user,
        array $branches,
        array $staffByBranch,
        int $adminId,
        int $securityId,
    ): array {
        $key = 'c'.$customerOrdinal.'-a'.$historyIndex;
        $status = $this->weighted($this->options->statusWeights, 'status', $key);
        $cancelledFrom = null;
        if ($status === CreditApplication::STATUS_CANCELLED) {
            $cancelledFrom = $this->weighted([
                CreditApplication::STATUS_DRAFT => 20,
                CreditApplication::STATUS_STEP_1_COMPLETED => 20,
                CreditApplication::STATUS_STEP_2_COMPLETED => 15,
                CreditApplication::STATUS_READY_FOR_VALIDATION_1 => 15,
                CreditApplication::STATUS_VALIDATION_1_COMPLETED => 10,
                CreditApplication::STATUS_VALIDATION_2 => 8,
                CreditApplication::STATUS_SUBMITTED => 8,
                CreditApplication::STATUS_STAFF_APPROVED => 4,
            ], 'cancelled-from', $key);
        }
        $path = $this->statePath->for($status, $cancelledFrom);
        $branch = $this->branch($branches, $key);
        $staffId = $staffByBranch[(int) $branch['id']];
        $createdAt = $this->applicationDate($customerOrdinal, $historyIndex, $historyCount);
        $submittedAt = $this->statePath->includes($path, CreditApplication::STATUS_SUBMITTED)
            ? $this->eventAt($createdAt, $path, CreditApplication::STATUS_SUBMITTED)
            : null;

        $clientCode = sprintf('SYN-CL-%s-%07d-%02d', strtoupper($this->options->runKey), $customerOrdinal, $historyIndex);
        $requestNumber = sprintf('SYN-CR-%s-%07d-%02d', strtoupper($this->options->runKey), $customerOrdinal, $historyIndex);
        $projectCode = sprintf('SYN-PJ-%s-%07d-%02d', strtoupper($this->options->runKey), $customerOrdinal, $historyIndex);
        $pidType = $this->chance('pid-type', $key, 15) ? 'Carte de séjour' : 'Passeport';
        $pidNumber = sprintf('SYN-%s-P%07d-%02d', strtoupper(substr($this->options->runKey, 0, 8)), $customerOrdinal, $historyIndex);
        $amounts = $this->amounts($key);
        $hasFailedValidation = $status === CreditApplication::STATUS_READY_FOR_VALIDATION_1
            && $this->chance('failed-validation', $key, 15);

        $reportEligible = in_array($status, [
            CreditApplication::STATUS_CANCELLED,
            CreditApplication::STATUS_STAFF_APPROVED,
            CreditApplication::STATUS_APPROVED,
            CreditApplication::STATUS_APPOINTMENT_PROPOSED,
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
            CreditApplication::STATUS_APPOINTMENT_LOCKED,
        ], true);
        $messageCount = $reportEligible && $this->chance('report-thread', $key, 35)
            ? $this->integer('message-count', $key, 1, 4)
            : 0;
        $reportClosed = $messageCount > 0 && $this->chance('report-closed', $key, 25);
        if ($reportClosed && $messageCount % 2 !== 0) {
            $messageCount++;
        }

        $decidedByStaff = $this->statePath->includes($path, CreditApplication::STATUS_STAFF_APPROVED)
            || $this->statePath->includes($path, CreditApplication::STATUS_STAFF_REJECTED)
            || ($status === CreditApplication::STATUS_CANCELLED && in_array($cancelledFrom, [CreditApplication::STATUS_SUBMITTED, CreditApplication::STATUS_STAFF_APPROVED], true));
        $decidedByAdmin = $this->statePath->includes($path, CreditApplication::STATUS_APPROVED)
            || $this->statePath->includes($path, CreditApplication::STATUS_REJECTED);

        $finalAt = $this->eventAt($createdAt, $path, end($path));
        $reportClosedAt = $reportClosed ? $finalAt->addMinutes($messageCount + 1) : null;
        $isRejected = in_array($status, [CreditApplication::STATUS_STAFF_REJECTED, CreditApplication::STATUS_REJECTED], true);

        return [
            'key' => $key,
            'customer_ordinal' => $customerOrdinal,
            'history_index' => $historyIndex,
            'user_id' => $userId,
            'user' => $user,
            'status' => $status,
            'cancelled_from' => $cancelledFrom,
            'path' => $path,
            'branch' => $branch,
            'staff_id' => $staffId,
            'admin_id' => $adminId,
            'security_id' => $securityId,
            'created_at' => $createdAt,
            'final_at' => $finalAt,
            'client_code' => $clientCode,
            'request_number' => $requestNumber,
            'project_code' => $projectCode,
            'pid_type' => $pidType,
            'pid_number' => $pidNumber,
            'amounts' => $amounts,
            'failed_validation' => $hasFailedValidation,
            'message_count' => $messageCount,
            'report_closed' => $reportClosed,
            'row' => [
                'user_id' => $userId,
                'status' => $status,
                'branch_id' => $submittedAt ? $branch['id'] : null,
                'report_closed_at' => $reportClosedAt?->format('Y-m-d H:i:s'),
                'report_closed_by_staff_id' => $reportClosed ? ($submittedAt ? $staffId : $adminId) : null,
                'report_closed_reason' => $reportClosed ? 'Clôture synthétique du fil de test.' : null,
                'submitted_at' => $submittedAt?->format('Y-m-d H:i:s'),
                'rejection_reason' => $isRejected ? 'Décision synthétique générée pour tester les scénarios de rejet.' : null,
                'decided_by_staff_user_id' => $decidedByStaff ? $staffId : null,
                'decided_by_admin_user_id' => $decidedByAdmin ? $adminId : null,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'updated_at' => ($reportClosedAt ?? $finalAt)->format('Y-m-d H:i:s'),
                'deleted_at' => null,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function clientRow(array $application, int $applicationId): ?array
    {
        if (! $this->statePath->includes($application['path'], CreditApplication::STATUS_STEP_1_COMPLETED)) {
            return null;
        }

        $ordinal = $application['customer_ordinal'];
        $key = $application['key'];
        $age = $this->integer('age', $key, 21, 64);
        $birthDate = $ordinal % 211 === 0
            ? CarbonImmutable::create(2000, 2, 29, 0, 0, 0, $this->options->anchor()->timezone)
            : $this->options->anchor()->subYears($age)->subDays($this->integer('birth-day', $key, 0, 364));
        $pidDate = $birthDate->addYears(18)->addDays($this->integer('pid-delay', $key, 30, max(31, ($age - 18) * 300)));
        if ($pidDate->greaterThan($this->options->anchor())) {
            $pidDate = $this->options->anchor()->subYear();
        }
        $created = $this->eventAt($application['created_at'], $application['path'], CreditApplication::STATUS_STEP_1_COMPLETED);

        return [
            'credit_application_id' => $applicationId,
            'code_client' => $application['client_code'],
            'civilite' => $application['user']['civilite'],
            'nom' => $application['user']['last_name'],
            'prenom' => $application['user']['first_name'],
            'nom_epoux' => null,
            'deuxieme_prenom' => null,
            'date_naissance' => $birthDate->toDateString(),
            'lieu_naissance' => $application['branch']['ville'],
            'pays_naissance' => 'Tunisie',
            'nationalite' => 'Tunisienne',
            'pays_residence' => 'Tunisie',
            'etat_civil' => $this->pick(['célibataire', 'marié', 'divorcé', 'veuf'], 'marital', $key),
            'nombre_enfants' => $ordinal % 97 === 0 ? 30 : $this->integer('children', $key, 0, 4),
            'type_pid' => $application['pid_type'],
            'numero_pid' => $application['pid_number'],
            'date_delivrance_pid' => $pidDate->toDateString(),
            'lieu_delivrance_pid' => $application['branch']['ville'],
            'numero_carte_sejour' => $application['pid_type'] === 'Carte de séjour' ? $application['pid_number'] : null,
            'profession' => $this->pick(self::PROFESSIONS, 'profession', $key),
            'date_entree_relation' => $this->options->anchor()->subYears($this->integer('relationship-age', $key, 0, 8))->toDateString(),
            'created_at' => $created->format('Y-m-d H:i:s'),
            'updated_at' => $created->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function creditRequestRow(array $application, int $applicationId): ?array
    {
        if (! $this->statePath->includes($application['path'], CreditApplication::STATUS_STEP_2_COMPLETED)) {
            return null;
        }

        $created = $this->eventAt($application['created_at'], $application['path'], CreditApplication::STATUS_STEP_2_COMPLETED);
        $received = $created->addDay();
        if ($received->greaterThan($this->options->anchor())) {
            $received = $this->options->anchor();
        }

        return [
            'credit_application_id' => $applicationId,
            'n_demande' => $application['request_number'],
            'identifiant_personne' => $application['client_code'],
            'nom_ou_rs' => $application['user']['last_name'],
            'prenom_ou_dc' => $application['user']['first_name'],
            'type_pid' => $application['pid_type'],
            'numero_pid' => $application['pid_number'],
            'origine' => 'générateur synthétique',
            'date_depot' => $created->toDateString(),
            'date_reception' => $received->toDateString(),
            'type_demande' => 'crédit professionnel synthétique',
            'code_devise' => 'TND',
            'montant_global_sollicite' => $application['amounts']['global'],
            'montant_eqp' => $application['amounts']['eqp'],
            'montant_fdr' => $application['amounts']['fdr'],
            'montant_amg' => $application['amounts']['amg'],
            'montant_chp' => $application['amounts']['chp'],
            'nombre_credits_sollicites' => 1,
            'unite_depot' => mb_substr($application['branch']['name'], 0, 100),
            'created_at' => $created->format('Y-m-d H:i:s'),
            'updated_at' => $created->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function projectRow(array $application, int $applicationId): ?array
    {
        if (! $this->statePath->includes($application['path'], CreditApplication::STATUS_STEP_3_COMPLETED)) {
            return null;
        }

        $created = $this->eventAt($application['created_at'], $application['path'], CreditApplication::STATUS_STEP_3_COMPLETED);
        $ordinal = $application['customer_ordinal'];

        return [
            'credit_application_id' => $applicationId,
            'code_projet' => $application['project_code'],
            'identifiant_personne' => $application['client_code'],
            'nom_ou_rs' => $application['user']['last_name'],
            'prenom_ou_dc' => $application['user']['first_name'],
            'type_projet' => $this->pick(self::PROJECT_TYPES, 'project-type', $application['key']),
            'objet' => $this->pick(self::PROJECT_OBJECTS, 'project-object', $application['key']),
            'adresse' => mb_substr('Adresse synthétique '.$ordinal.', zone de test '.$application['branch']['delegation'], 0, 255),
            'ville' => mb_substr($application['branch']['ville'], 0, 100),
            'code_postal' => sprintf('%04d', 1_000 + ($ordinal % 8_000)),
            'activite' => $this->pick(self::ACTIVITIES, 'activity', $application['key']),
            'description' => 'Projet entièrement synthétique généré pour les tests BTS Bank. Aucun bénéficiaire réel.',
            'delegation' => mb_substr($application['branch']['delegation'], 0, 150),
            'localisation' => mb_substr('Zone de test '.$application['branch']['ville'], 0, 255),
            'latitude' => $application['branch']['latitude'],
            'longitude' => $application['branch']['longitude'],
            'cout' => $application['amounts']['cost'],
            'investissement_personnel' => $application['amounts']['personal'],
            'financement' => $application['amounts']['global'],
            'revenus' => $application['amounts']['revenue'],
            'depenses' => $application['amounts']['expenses'],
            'created_at' => $created->format('Y-m-d H:i:s'),
            'updated_at' => $created->format('Y-m-d H:i:s'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function documentRows(array $application, int $applicationId): array
    {
        $needsDocument = $this->statePath->includes($application['path'], CreditApplication::STATUS_VALIDATION_1_COMPLETED)
            || $application['failed_validation'];
        if (! $needsDocument) {
            return [];
        }

        $identityType = $application['pid_type'] === 'Carte de séjour' ? 'carte_sejour' : 'passport';
        $types = $application['failed_validation'] ? [$identityType] : [$identityType];
        if (! $application['failed_validation']) {
            foreach (['eqp', 'fdr', 'amg', 'chp'] as $financeType) {
                if ($application['amounts'][$financeType.'_millimes'] > 0) {
                    $types[] = $financeType;
                }
            }
        }
        if (! $application['failed_validation'] && $this->chance('epr-document', $application['key'], 3)) {
            $types[] = 'epr';
        }

        $verifiedAt = $this->eventAt($application['created_at'], $application['path'], CreditApplication::STATUS_VALIDATION_1_COMPLETED);
        $rows = [];
        foreach (array_values(array_unique($types)) as $index => $type) {
            $path = $this->documentPath($application, $type, $index + 1);
            $content = $this->documentContent($application, $type);
            $valid = ! $application['failed_validation'];
            $rows[] = [
                'credit_application_id' => $applicationId,
                'document_type' => $type,
                'original_filename' => 'synthetic-'.$type.'-'.$application['key'].'.txt',
                'disk_path' => $path,
                'mime_type' => 'text/plain',
                'size_bytes' => strlen($content),
                'ai_verified_at' => $verifiedAt->format('Y-m-d H:i:s'),
                'ai_is_valid' => $valid,
                'ai_confidence' => $valid ? 'high' : 'low',
                'ai_comment' => $valid
                    ? 'Document synthétique valide pour tests automatisés.'
                    : 'Cas limite synthétique: document volontairement invalide.',
                'ai_extracted_fields' => $this->json(['pid_number' => (string) $application['pid_number'], 'synthetic' => true]),
                'ai_mismatches' => $this->json($valid ? [] : [[
                    'field' => 'pid_number',
                    'expected' => (string) $application['pid_number'],
                    'extracted' => 'SYNTHETIC_INVALID',
                    'severity' => 'critical',
                ]]),
                'ai_processing_status' => $valid ? 'verified' : 'needs_human_review',
                'ai_detected_issues' => $this->json($valid ? [] : [[
                    'type' => 'synthetic_invalid_document',
                    'severity' => 'warning',
                    'message' => 'Cas limite synthétique pour test de revue humaine.',
                ]]),
                'ai_requires_human_review' => ! $valid,
                'created_at' => $verifiedAt->subHour()->format('Y-m-d H:i:s'),
                'updated_at' => $verifiedAt->format('Y-m-d H:i:s'),
                'deleted_at' => null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function validationRows(array $application, int $applicationId): array
    {
        if ($application['failed_validation']) {
            return [[
                'credit_application_id' => $applicationId,
                'step' => ValidationStep::STEP_VALIDATION_1,
                'status' => ValidationStep::STATUS_FAILED,
                'errors' => $this->json(['Cas limite synthétique: document à réviser.']),
                'created_at' => $application['final_at']->format('Y-m-d H:i:s'),
            ]];
        }

        if (! $this->statePath->includes($application['path'], CreditApplication::STATUS_VALIDATION_1_COMPLETED)) {
            return [];
        }

        $passedAt = $this->eventAt($application['created_at'], $application['path'], CreditApplication::STATUS_VALIDATION_1_COMPLETED);
        $rows = [];
        if ($this->chance('validation-retry', $application['key'], 10)) {
            $rows[] = [
                'credit_application_id' => $applicationId,
                'step' => ValidationStep::STEP_VALIDATION_1,
                'status' => ValidationStep::STATUS_FAILED,
                'errors' => $this->json(['Échec synthétique corrigé avant validation finale.']),
                'created_at' => $passedAt->subDay()->format('Y-m-d H:i:s'),
            ];
        }
        $rows[] = [
            'credit_application_id' => $applicationId,
            'step' => ValidationStep::STEP_VALIDATION_1,
            'status' => ValidationStep::STATUS_PASSED,
            'errors' => null,
            'created_at' => $passedAt->format('Y-m-d H:i:s'),
        ];

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function appointmentRows(array $application, int $applicationId, AppointmentSlotAllocator $allocator): array
    {
        if (! in_array($application['status'], [
            CreditApplication::STATUS_APPOINTMENT_PROPOSED,
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
            CreditApplication::STATUS_APPOINTMENT_LOCKED,
        ], true)) {
            return [];
        }

        $attempts = $application['status'] === CreditApplication::STATUS_APPOINTMENT_LOCKED
            ? Appointment::MAX_ATTEMPTS
            : $this->integer('appointment-attempts', $application['key'], 1, 4);
        $rows = [];
        $excluded = [];
        $from = $this->eventAt(
            $application['created_at'],
            $application['path'],
            CreditApplication::STATUS_APPROVED,
        )->addDay();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $isLast = $attempt === $attempts;
            $status = Appointment::STATUS_REJECTED;
            if ($isLast && $application['status'] === CreditApplication::STATUS_APPOINTMENT_PROPOSED) {
                $status = Appointment::STATUS_PROPOSED;
            } elseif ($isLast && $application['status'] === CreditApplication::STATUS_APPOINTMENT_CONFIRMED) {
                $status = Appointment::STATUS_ACCEPTED;
            }
            $reserve = in_array($status, [Appointment::STATUS_PROPOSED, Appointment::STATUS_ACCEPTED], true);
            $slot = $allocator->allocate($application['branch'], $from, $reserve, $excluded);
            $excluded[$slot['key']] = true;
            $created = CarbonImmutable::parse($slot['date'].' '.$slot['time'])->subDay();
            $decided = $status === Appointment::STATUS_PROPOSED ? null : $created->addHours(4);

            $rows[] = [
                'credit_application_id' => $applicationId,
                'branch_id' => $application['branch']['id'],
                'attempt_number' => $attempt,
                'scheduled_date' => $slot['date'],
                'scheduled_time' => $slot['time'],
                'status' => $status,
                'decided_at' => $decided?->format('Y-m-d H:i:s'),
                'is_auto_scheduled_future' => $slot['is_auto_scheduled_future'],
                'created_at' => $created->format('Y-m-d H:i:s'),
            ];
            $from = CarbonImmutable::parse($slot['date'])->addDay();
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function reportRows(array $application, int $applicationId): array
    {
        $rows = [];
        for ($index = 1; $index <= $application['message_count']; $index++) {
            $customer = $index % 2 === 1;
            $rows[] = [
                'credit_application_id' => $applicationId,
                'sender_type' => $customer ? ReportMessage::SENDER_CUSTOMER : ReportMessage::SENDER_STAFF,
                'user_id' => $customer ? $application['user_id'] : null,
                'staff_user_id' => $customer ? null : ($application['row']['branch_id'] ? $application['staff_id'] : $application['admin_id']),
                'body' => $customer
                    ? 'Message client entièrement synthétique pour tester le suivi du dossier.'
                    : 'Réponse agent entièrement synthétique pour tester le suivi du dossier.',
                'attachment_path' => null,
                'attachment_name' => null,
                'attachment_type' => null,
                'attachment_size' => null,
                'created_at' => $application['final_at']->addMinutes($index)->format('Y-m-d H:i:s'),
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function auditRows(array $application, int $applicationId): array
    {
        $rows = [[
            'user_id' => $application['user_id'],
            'staff_user_id' => null,
            'credit_application_id' => $applicationId,
            'action' => 'credit_application.created',
            'previous_state' => null,
            'new_state' => $this->json(['status' => CreditApplication::STATUS_DRAFT, 'synthetic' => true]),
            'ip_address' => $this->testIp($application['key']),
            'user_agent' => 'BTS-Synthetic-Generator/1.0',
            'created_at' => $application['created_at']->format('Y-m-d H:i:s'),
        ]];

        $path = $application['path'];
        for ($index = 1; $index < count($path); $index++) {
            $next = $path[$index];
            $prior = $path[$index - 1];
            $staffActor = in_array($next, [
                CreditApplication::STATUS_STAFF_APPROVED,
                CreditApplication::STATUS_STAFF_REJECTED,
                CreditApplication::STATUS_APPROVED,
                CreditApplication::STATUS_REJECTED,
            ], true) || ($next === CreditApplication::STATUS_CANCELLED && in_array($prior, [
                CreditApplication::STATUS_SUBMITTED,
                CreditApplication::STATUS_STAFF_APPROVED,
            ], true));
            $actorId = in_array($next, [CreditApplication::STATUS_APPROVED, CreditApplication::STATUS_REJECTED], true)
                ? $application['admin_id']
                : $application['staff_id'];

            $rows[] = [
                'user_id' => $staffActor ? null : $application['user_id'],
                'staff_user_id' => $staffActor ? $actorId : null,
                'credit_application_id' => $applicationId,
                'action' => 'credit_application.status_changed',
                'previous_state' => $this->json(['status' => $prior]),
                'new_state' => $this->json(['status' => $next, 'synthetic' => true]),
                'ip_address' => $this->testIp($application['key'].'-'.$index),
                'user_agent' => 'BTS-Synthetic-Generator/1.0',
                'created_at' => $this->eventAt($application['created_at'], $path, $next)->format('Y-m-d H:i:s'),
            ];
        }

        if ($this->chance('security-audit', $application['key'], 5)) {
            $action = $this->pick(['audit.osquery_query', 'security.vulnerability_scanned', 'auth.login_failed'], 'security-action', $application['key']);
            $rows[] = [
                'user_id' => null,
                'staff_user_id' => $application['security_id'],
                'credit_application_id' => $applicationId,
                'action' => $action,
                'previous_state' => $this->json(['source' => 'synthetic-test-data']),
                'new_state' => $this->json(['result' => 'synthetic', 'row_count' => $this->integer('security-rows', $application['key'], 0, 250)]),
                'ip_address' => $this->testIp('security-'.$application['key']),
                'user_agent' => 'BTS-Synthetic-Security-Agent/1.0',
                'created_at' => $application['final_at']->format('Y-m-d H:i:s'),
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function notificationRows(array $application, int $applicationId): array
    {
        if ($application['status'] === CreditApplication::STATUS_DRAFT) {
            return [];
        }

        $type = match ($application['status']) {
            CreditApplication::STATUS_STAFF_REJECTED, CreditApplication::STATUS_REJECTED => 'application.rejected',
            CreditApplication::STATUS_CANCELLED => 'application.cancelled',
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => 'appointment.created',
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => 'appointment.confirmed',
            CreditApplication::STATUS_APPOINTMENT_LOCKED => 'appointment.locked',
            default => 'application.status_changed',
        };

        return [[
            'notifiable_type' => $this->userMorphClass,
            'notifiable_id' => $application['user_id'],
            'type' => $type,
            'title' => 'Notification synthétique BTS',
            'body' => 'Événement synthétique pour le dossier '.$application['request_number'].'.',
            'data' => $this->json(['application_id' => $applicationId, 'synthetic' => true]),
            'dedupe_key' => 'syn-'.$this->options->runKey.'-'.$application['key'].'-'.$type,
            'read_at' => $this->chance('notification-read', $application['key'], 65)
                ? $application['final_at']->addHour()->format('Y-m-d H:i:s')
                : null,
            'created_at' => $application['final_at']->format('Y-m-d H:i:s'),
            'updated_at' => $application['final_at']->format('Y-m-d H:i:s'),
        ]];
    }

    /** @return array<string,mixed>|null */
    public function otpRow(int $ordinal, int $userId, string $hash): ?array
    {
        if (! $this->chance('otp', 'c'.$ordinal, 10)) {
            return null;
        }

        $created = $this->options->anchor()->subDays($this->integer('otp-age', 'c'.$ordinal, 10, 800));

        return [
            'user_id' => $userId,
            'code_hash' => $hash,
            'purpose' => $this->pick(['registration', 'login', 'password_reset'], 'otp-purpose', 'c'.$ordinal),
            'channel' => $this->chance('otp-channel', 'c'.$ordinal, 30) ? 'email' : 'sms',
            'expires_at' => $created->addMinutes(10)->format('Y-m-d H:i:s'),
            'consumed_at' => $created->addMinutes(2)->format('Y-m-d H:i:s'),
            'attempt_count' => $this->integer('otp-attempts', 'c'.$ordinal, 0, 2),
            'created_at' => $created->format('Y-m-d H:i:s'),
        ];
    }

    public function documentPath(array $application, string $type, int $index): string
    {
        return trim((string) config('synthetic_data.document_directory', 'synthetic-data'), '/')
            .'/'.$this->options->runKey
            .'/c'.$application['customer_ordinal']
            .'/a'.$application['history_index']
            .'/'.$type.'-'.$index.'.txt';
    }

    public function documentContent(array $application, string $type): string
    {
        return implode("\n", [
            'SYNTHETIC TEST DOCUMENT - NOT A REAL BANK RECORD',
            'run='.$this->options->runKey,
            'application='.$application['key'],
            'type='.$type,
            'pid='.$application['pid_number'],
        ])."\n";
    }

    /** @param list<array<string,mixed>> $branches */
    private function branch(array $branches, string $key): array
    {
        $routableBranches = array_values(array_filter(
            $branches,
            fn (array $branch): bool => (bool) ($branch['routing_enabled'] ?? true),
        ));
        if ($routableBranches === []) {
            $routableBranches = $branches;
        }

        $weights = [];
        foreach ($routableBranches as $index => $branch) {
            $weights[(string) $index] = max(1, (int) $branch['daily_capacity'])
                * $this->integer('branch-weight', (string) $branch['id'], 4, 14);
        }
        $selected = (int) $this->weighted($weights, 'branch', $key);

        return $routableBranches[$selected];
    }

    private function applicationDate(int $customerOrdinal, int $historyIndex, int $historyCount): CarbonImmutable
    {
        $key = 'c'.$customerOrdinal;
        $yearWeights = array_slice($this->options->yearWeights, 0, $this->options->years);
        $yearChoices = [];
        foreach ($yearWeights as $offset => $weight) {
            $yearChoices[(string) $offset] = (int) $weight;
        }
        $yearOffset = (int) $this->weighted($yearChoices, 'application-year', $key);

        $monthWeights = [];
        foreach ($this->options->monthWeights as $index => $weight) {
            $monthWeights[(string) ($index + 1)] = (int) $weight;
        }
        $month = (int) $this->weighted($monthWeights, 'application-month', $key);
        $year = $this->options->anchor()->year - $yearOffset;
        $date = CarbonImmutable::create(
            $year,
            $month,
            $this->integer('application-day', $key, 1, 28),
            $this->integer('application-hour', $key, 7, 20),
            $this->integer('application-minute', $key, 0, 59),
            $this->integer('application-second', $key, 0, 59),
            $this->options->anchor()->timezone,
        );
        if ($date->greaterThan($this->options->anchor())) {
            $date = $date->subYear();
        }

        $gapDays = 120 + $this->integer('history-gap', $key, 0, 240);

        return $date->subDays(($historyCount - $historyIndex) * $gapDays);
    }

    /** @param list<string> $path */
    private function eventAt(CarbonImmutable $createdAt, array $path, string $status): CarbonImmutable
    {
        $index = array_search($status, $path, true);
        $index = $index === false ? count($path) - 1 : $index;
        $candidate = $createdAt->addDays($index * 3);

        return $candidate->greaterThan($this->options->anchor()) ? $this->options->anchor() : $candidate;
    }

    /** @return array<string,string|int> */
    private function amounts(string $key): array
    {
        $band = $this->weighted(['small' => 55, 'medium' => 30, 'large' => 12, 'edge' => 3], 'amount-band', $key);
        [$minimum, $maximum] = match ($band) {
            'small' => [3_000, 20_000],
            'medium' => [20_500, 50_000],
            'large' => [50_500, 100_000],
            default => [100_500, 200_000],
        };
        $global = $this->chance('amount-lower-boundary', $key, 1)
            ? $minimum * 1_000
            : ($this->chance('amount-upper-boundary', $key, 1)
                ? $maximum * 1_000
                : $this->integer('global-amount', $key, intdiv($minimum, 500), intdiv($maximum, 500)) * 500_000);
        $categories = $this->randomizer('finance-categories', $key)->shuffleArray(['eqp', 'fdr', 'amg', 'chp']);
        $activeCount = (int) $this->weighted(['1' => 20, '2' => 35, '3' => 30, '4' => 15], 'finance-category-count', $key);
        $active = array_slice($categories, 0, $activeCount);
        $weights = [];
        foreach ($active as $category) {
            $weights[$category] = $this->integer('finance-weight-'.$category, $key, 1, 10);
        }
        $breakdown = ['eqp' => 0, 'fdr' => 0, 'amg' => 0, 'chp' => 0];
        $remaining = $global;
        $weightTotal = array_sum($weights);
        $lastCategory = array_key_last($weights);
        foreach ($weights as $category => $weight) {
            $amount = $category === $lastCategory ? $remaining : intdiv($global * $weight, $weightTotal);
            $breakdown[$category] = $amount;
            $remaining -= $amount;
        }
        $eqp = $breakdown['eqp'];
        $fdr = $breakdown['fdr'];
        $amg = $breakdown['amg'];
        $chp = $breakdown['chp'];
        $personal = intdiv($global * $this->integer('personal-share', $key, 1_500, 4_000), 10_000);
        $cost = $global + $personal;
        $revenue = intdiv($global * $this->integer('revenue-share', $key, 800, 2_000), 10_000);
        $expenses = intdiv($revenue * $this->integer('expense-share', $key, 350, 700), 1_000);

        return [
            'global_millimes' => $global,
            'eqp_millimes' => $eqp,
            'fdr_millimes' => $fdr,
            'amg_millimes' => $amg,
            'chp_millimes' => $chp,
            'global' => $this->decimal($global),
            'eqp' => $this->decimal($eqp),
            'fdr' => $this->decimal($fdr),
            'amg' => $this->decimal($amg),
            'chp' => $this->decimal($chp),
            'personal' => $this->decimal($personal),
            'cost' => $this->decimal($cost),
            'revenue' => $this->decimal($revenue),
            'expenses' => $this->decimal($expenses),
        ];
    }

    private function decimal(int $millimes): string
    {
        return sprintf('%d.%03d', intdiv($millimes, 1_000), $millimes % 1_000);
    }

    private function testIp(string $key): string
    {
        return '192.0.2.'.$this->integer('ip', $key, 1, 254);
    }

    private function chance(string $scope, string $key, int $percent): bool
    {
        return $this->integer($scope, $key, 1, 100) <= $percent;
    }

    /** @param list<string> $values */
    private function pick(array $values, string $scope, string $key): string
    {
        return $values[$this->integer($scope, $key, 0, count($values) - 1)];
    }

    /** @param array<string,int> $weights */
    private function weighted(array $weights, string $scope, string $key): string
    {
        $total = array_sum($weights);
        $target = $this->integer($scope, $key, 1, max(1, $total));
        foreach ($weights as $value => $weight) {
            $target -= $weight;
            if ($target <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }

    private function integer(string $scope, string $key, int $minimum, int $maximum): int
    {
        return $this->randomizer($scope, $key)->getInt($minimum, $maximum);
    }

    private function randomizer(string $scope, string $key): Randomizer
    {
        $seed = hash('sha256', implode('|', [$this->options->seed, $this->options->runKey, $scope, $key]), true);

        return new Randomizer(new Xoshiro256StarStar($seed));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
