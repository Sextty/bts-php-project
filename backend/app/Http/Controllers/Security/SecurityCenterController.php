<?php

namespace App\Http\Controllers\Security;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\Document;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AuditLogService;
use App\Services\Osquery\OsqueryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SecurityCenterController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly AuditLogService $auditLogService,
        private readonly OsqueryEngine $osqueryEngine,
    ) {}

    /**
     * Real-time Security Center Dashboard Metrics.
     */
    public function dashboard(Request $request): JsonResponse
    {
        /** @var StaffUser $viewer */
        $viewer = $request->user();

        $today = Carbon::today();

        $eventsToday = AuditLog::where('created_at', '>=', $today)->count();
        $totalEvents = AuditLog::count();
        $activeCustomers = User::where('status', 'active')->count();
        $suspendedCustomers = User::where('status', 'suspended')->count();
        $activeStaff = StaffUser::where('status', 'active')->count();

        // Traffic over last 7 days
        $traffic = $this->activityLogService->traffic($viewer, 7);

        // Recent security & auth events
        $recentEvents = $this->activityLogService->paginate($viewer, [], 8);

        // Quick host telemetry for status card
        $uptime = $this->osqueryEngine->getTableData('uptime')[0] ?? null;
        $os = $this->osqueryEngine->getTableData('os_version')[0] ?? null;
        $ports = $this->osqueryEngine->getTableData('listening_ports');

        return ApiResponse::ok([
            'metrics' => [
                'events_today' => $eventsToday,
                'total_events' => $totalEvents,
                'active_customers' => $activeCustomers,
                'suspended_customers' => $suspendedCustomers,
                'active_staff' => $activeStaff,
                'open_ports_count' => count($ports),
            ],
            'system' => [
                'os_name' => $os['name'] ?? 'Inconnu',
                'platform' => $os['platform'] ?? 'Inconnu',
                'uptime_days' => $uptime['days'] ?? 0,
                'uptime_hours' => $uptime['hours'] ?? 0,
                'uptime_seconds' => $uptime['total_seconds'] ?? 0,
            ],
            'traffic' => $traffic,
            'recent_events' => $recentEvents['items'],
        ]);
    }

    /**
     * Real paginated audit journal logs.
     */
    public function activity(Request $request): JsonResponse
    {
        /** @var StaffUser $viewer */
        $viewer = $request->user();

        $filters = [
            'action' => $request->query('action'),
            'search' => $request->query('search'),
            'actor_type' => $request->query('actor_type'),
            'application_id' => $request->query('application_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $result = $this->activityLogService->paginate($viewer, $filters, $perPage);
        $availableActions = $this->activityLogService->availableActions($viewer);

        return ApiResponse::ok([
            'items' => $result['items'],
            'meta' => $result['meta'],
            'available_actions' => $availableActions,
        ]);
    }

    /**
     * Single audit log entry detail.
     */
    public function activityDetail(int $id, Request $request): JsonResponse
    {
        /** @var StaffUser $viewer */
        $viewer = $request->user();

        $log = $this->activityLogService->find($id, $viewer);

        if (! $log) {
            throw new ApiException(ApiErrorCode::NotFound, 'Audit log record not found.');
        }

        return ApiResponse::ok(['event' => $log]);
    }

    /**
     * Real list of users for security oversight.
     */
    public function users(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $status = $request->query('status');
        $type = $request->query('type', 'all');

        $usersQuery = User::query()->withCount('tokens')->latest('created_at');

        if (! empty($search)) {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($status)) {
            $usersQuery->where('status', $status);
        }

        $customersPage = $usersQuery->paginate(20, ['*'], 'customers_page');

        $staffQuery = StaffUser::query()->withCount('tokens')->with('branch:id,name,ville')->latest('created_at');
        if (! empty($search)) {
            $staffQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if (! empty($status)) {
            $staffQuery->where('status', $status);
        }

        $staffPage = $staffQuery->paginate(20, ['*'], 'staff_page');

        return ApiResponse::ok([
            'customers' => [
                'items' => collect($customersPage->items())->map(fn (User $u) => [
                    'id' => $u->id,
                    'type' => 'customer',
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'status' => $u->status,
                    'banned_at' => $u->banned_at?->toIso8601String(),
                    'banned_reason' => $u->banned_reason,
                    'banned_by_staff_id' => $u->banned_by_staff_id,
                    'active_tokens_count' => $u->tokens_count,
                    'created_at' => $u->created_at?->toIso8601String(),
                ])->all(),
                'meta' => [
                    'current_page' => $customersPage->currentPage(),
                    'last_page' => $customersPage->lastPage(),
                    'total' => $customersPage->total(),
                ],
            ],
            'staff' => [
                'items' => collect($staffPage->items())->map(fn (StaffUser $s) => [
                    'id' => $s->id,
                    'type' => 'staff',
                    'first_name' => $s->first_name,
                    'last_name' => $s->last_name,
                    'email' => $s->email,
                    'role' => $s->role,
                    'status' => $s->status,
                    'branch' => $s->branch ? ['id' => $s->branch->id, 'name' => $s->branch->name, 'ville' => $s->branch->ville] : null,
                    'active_tokens_count' => $s->tokens_count,
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->all(),
                'meta' => [
                    'current_page' => $staffPage->currentPage(),
                    'last_page' => $staffPage->lastPage(),
                    'total' => $staffPage->total(),
                ],
            ],
        ]);
    }

    /**
     * Suspend a customer user with mandatory reason and token revocation.
     */
    public function suspendUser(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        /** @var StaffUser $staff */
        $staff = $request->user();

        $user = User::find($id);
        if (! $user) {
            throw new ApiException(ApiErrorCode::NotFound, 'User not found.');
        }

        if ($user->status === 'suspended') {
            throw new ApiException(ApiErrorCode::Conflict, 'User is already suspended.');
        }

        $user->ban($validated['reason'], $staff);

        $this->auditLogService->log(
            action: 'security.user_suspended',
            user: $user,
            previousState: ['status' => 'active'],
            newState: ['status' => 'suspended', 'reason' => $validated['reason']],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            staffUser: $staff,
        );

        return ApiResponse::ok([
            'message' => 'User suspended successfully and all tokens revoked.',
            'user' => [
                'id' => $user->id,
                'status' => $user->status,
                'banned_at' => $user->banned_at?->toIso8601String(),
                'banned_reason' => $user->banned_reason,
            ],
        ]);
    }

    /**
     * Unsuspend a customer user.
     */
    public function unsuspendUser(int $id, Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $user = User::find($id);
        if (! $user) {
            throw new ApiException(ApiErrorCode::NotFound, 'User not found.');
        }

        $previousReason = $user->banned_reason;
        $user->unban();

        $this->auditLogService->log(
            action: 'security.user_unsuspended',
            user: $user,
            previousState: ['status' => 'suspended', 'reason' => $previousReason],
            newState: ['status' => 'active'],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            staffUser: $staff,
        );

        return ApiResponse::ok([
            'message' => 'User unbanned successfully.',
            'user' => [
                'id' => $user->id,
                'status' => $user->status,
            ],
        ]);
    }

    /** Revoke active tokens for an explicitly typed customer or staff account. */
    public function revokeTokens(int $id, Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $validated = $request->validate(['type' => 'sometimes|in:customer,staff']);
        $targetType = $validated['type'] ?? 'customer';
        $isStaffTarget = $targetType === 'staff';
        $target = $isStaffTarget ? StaffUser::find($id) : User::find($id);
        if (! $target) {
            throw new ApiException(ApiErrorCode::NotFound, 'User not found.');
        }

        $tokenCount = $target->tokens()->count();
        $target->tokens()->delete();

        $this->auditLogService->log(
            action: 'security.tokens_revoked',
            user: $isStaffTarget ? null : $target,
            previousState: ['target_type' => $targetType, 'target_id' => $id, 'tokens_count' => $tokenCount],
            newState: ['target_type' => $targetType, 'target_id' => $id, 'tokens_count' => 0],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            staffUser: $staff,
        );

        return ApiResponse::ok([
            'message' => "Successfully revoked {$tokenCount} active tokens for user {$target->email}.",
            'revoked_count' => $tokenCount,
        ]);
    }

    /**
     * Real Credit Applications compliance & audit list.
     */
    public function applications(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $search = $request->query('search');

        $query = CreditApplication::query()
            ->with([
                'user:id,first_name,last_name,email',
                'branch:id,name,ville',
                'creditRequest:credit_application_id,montant_global_sollicite,n_demande',
                'validationSteps:credit_application_id,step,status',
            ])
            ->latest('created_at');

        if (! empty($status)) {
            $query->where('status', $status);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('creditRequest', function ($cq) use ($search) {
                        $cq->where('n_demande', 'like', "%{$search}%");
                    });
            });
        }

        $page = $query->paginate(25);

        return ApiResponse::ok([
            'items' => collect($page->items())->map(fn (CreditApplication $app) => [
                'id' => $app->id,
                'status' => $app->status,
                'submitted_at' => $app->submitted_at?->toIso8601String(),
                'created_at' => $app->created_at?->toIso8601String(),
                'user' => $app->user ? ['id' => $app->user->id, 'name' => trim($app->user->first_name.' '.$app->user->last_name), 'email' => $app->user->email] : null,
                'branch' => $app->branch ? ['id' => $app->branch->id, 'name' => $app->branch->name, 'ville' => $app->branch->ville] : null,
                'n_demande' => $app->creditRequest?->n_demande,
                'montant' => $app->creditRequest?->montant_global_sollicite,
                'validation_steps' => $app->validationSteps->map(fn ($v) => ['step' => $v->step, 'status' => $v->status]),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Real Documents metadata audit list (zero raw disk paths exposed).
     */
    public function documents(Request $request): JsonResponse
    {
        $page = Document::query()
            ->with(['creditApplication:id,status,user_id', 'creditApplication.user:id,first_name,last_name'])
            ->latest('created_at')
            ->paginate(25);

        return ApiResponse::ok([
            'items' => collect($page->items())->map(fn (Document $doc) => [
                'id' => $doc->id,
                'credit_application_id' => $doc->credit_application_id,
                'document_type' => $doc->document_type,
                'original_filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'ai_verified_at' => $doc->ai_verified_at?->toIso8601String(),
                'ai_is_valid' => $doc->ai_is_valid,
                'ai_confidence' => $doc->ai_confidence,
                'ai_comment' => $doc->ai_comment,
                'ai_requires_human_review' => $doc->ai_requires_human_review,
                'created_at' => $doc->created_at?->toIso8601String(),
                'applicant_name' => $doc->creditApplication?->user ? trim($doc->creditApplication->user->first_name.' '.$doc->creditApplication->user->last_name) : 'N/A',
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Real Appointments compliance list.
     */
    public function appointments(Request $request): JsonResponse
    {
        $page = Appointment::query()
            ->with(['branch:id,name,ville', 'creditApplication:id,user_id', 'creditApplication.user:id,first_name,last_name,email'])
            ->latest('scheduled_date')
            ->paginate(25);

        return ApiResponse::ok([
            'items' => collect($page->items())->map(fn (Appointment $apt) => [
                'id' => $apt->id,
                'credit_application_id' => $apt->credit_application_id,
                'scheduled_date' => $apt->scheduled_date?->format('Y-m-d'),
                'scheduled_time' => $apt->scheduled_time,
                'status' => $apt->status,
                'attempt_number' => $apt->attempt_number,
                'branch' => $apt->branch ? ['id' => $apt->branch->id, 'name' => $apt->branch->name, 'ville' => $apt->branch->ville] : null,
                'applicant_name' => $apt->creditApplication?->user ? trim($apt->creditApplication->user->first_name.' '.$apt->creditApplication->user->last_name) : 'N/A',
                'created_at' => $apt->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Real Host System Telemetry.
     */
    public function telemetry(Request $request): JsonResponse
    {
        $systemInfo = $this->osqueryEngine->getTableData('system_info')[0] ?? null;
        $osVersion = $this->osqueryEngine->getTableData('os_version')[0] ?? null;
        $interfaces = $this->osqueryEngine->getTableData('interface_details');
        $ports = $this->osqueryEngine->getTableData('listening_ports');
        $memory = $this->osqueryEngine->getTableData('memory_info')[0] ?? null;
        $disks = $this->osqueryEngine->getTableData('disk_info');
        $uptime = $this->osqueryEngine->getTableData('uptime')[0] ?? null;
        $kernel = $this->osqueryEngine->getTableData('kernel_info')[0] ?? null;
        $platform = $this->osqueryEngine->getTableData('platform_info')[0] ?? null;

        return ApiResponse::ok([
            'system_info' => $systemInfo,
            'os_version' => $osVersion,
            'interfaces' => $interfaces,
            'listening_ports' => $ports,
            'memory_info' => $memory,
            'disks' => $disks,
            'uptime' => $uptime,
            'kernel' => $kernel,
            'platform' => $platform,
        ]);
    }

    /**
     * Real Network & Port Security Scan against baseline.
     */
    public function vulnerabilities(Request $request): JsonResponse
    {
        $ports = $this->osqueryEngine->getTableData('listening_ports');
        $analysis = $this->analyzePortSecurity($ports);

        return ApiResponse::ok([
            'last_scanned_at' => now()->toIso8601String(),
            'ports_analyzed_count' => count($ports),
            'findings' => $analysis['findings'],
            'summary' => $analysis['summary'],
        ]);
    }

    /**
     * Trigger a real vulnerability & port inspection scan with audit trail.
     */
    public function scanVulnerabilities(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $ports = $this->osqueryEngine->getTableData('listening_ports');
        $analysis = $this->analyzePortSecurity($ports);

        $this->auditLogService->log(
            action: 'security.vulnerability_scanned',
            previousState: [],
            newState: [
                'ports_analyzed' => count($ports),
                'findings_count' => count($analysis['findings']),
                'risk_level' => $analysis['summary']['overall_risk'],
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            staffUser: $staff,
        );

        return ApiResponse::ok([
            'message' => 'Security scan completed successfully.',
            'scanned_at' => now()->toIso8601String(),
            'ports_analyzed_count' => count($ports),
            'findings' => $analysis['findings'],
            'summary' => $analysis['summary'],
        ]);
    }

    private function analyzePortSecurity(array $ports): array
    {
        $findings = [];
        $critical = 0;
        $warning = 0;
        $info = 0;

        foreach ($ports as $p) {
            $port = (int) ($p['port'] ?? 0);
            $addr = $p['address'] ?? '0.0.0.0';
            $pName = $p['process_name'] ?? 'Inconnu';
            $isPublic = ($addr === '0.0.0.0' || $addr === '::');

            if ($port === 80 && $isPublic) {
                $findings[] = [
                    'severity' => 'warning',
                    'title' => 'Serveur HTTP en clair sur port public 80',
                    'description' => "Le service '{$pName}' écoute sur toutes les interfaces (0.0.0.0:80) sans chiffrement TLS obligatoire.",
                    'port' => $port,
                    'address' => $addr,
                    'recommendation' => 'Forcer la redirection HTTPS (port 443) et activer HSTS.',
                ];
                $warning++;
            } elseif ($port === 3306 && $isPublic) {
                $findings[] = [
                    'severity' => 'critical',
                    'title' => 'Base de données MySQL exposée publiquement (3306)',
                    'description' => "Le serveur MySQL écoute sur {$addr}:3306 au lieu de localhost (127.0.0.1).",
                    'port' => $port,
                    'address' => $addr,
                    'recommendation' => 'Lier MySQL sur bind-address = 127.0.0.1 dans my.cnf.',
                ];
                $critical++;
            } elseif ($port === 23 || $port === 21) {
                $findings[] = [
                    'severity' => 'critical',
                    'title' => 'Protocole non sécurisé détecté (Telnet/FTP)',
                    'description' => "Port {$port} actif utilisant un protocole transmettant les mots de passe en clair.",
                    'port' => $port,
                    'address' => $addr,
                    'recommendation' => 'Désactiver ce service et remplacer par SSH/SFTP.',
                ];
                $critical++;
            } elseif ($isPublic && in_array($port, [8000, 3000, 3001, 3002, 3003], true)) {
                $findings[] = [
                    'severity' => 'info',
                    'title' => "Serveur d'application BTS Bank actif (Port {$port})",
                    'description' => "Service '{$pName}' opérationnel sur {$addr}:{$port}.",
                    'port' => $port,
                    'address' => $addr,
                    'recommendation' => 'Maintenir les certificats TLS et la passerelle inverse à jour.',
                ];
                $info++;
            }
        }

        $overallRisk = $critical > 0 ? 'CRITICAL' : ($warning > 0 ? 'WARNING' : 'HEALTHY');

        return [
            'findings' => $findings,
            'summary' => [
                'critical_count' => $critical,
                'warning_count' => $warning,
                'info_count' => $info,
                'overall_risk' => $overallRisk,
            ],
        ];
    }

    /**
     * Export real filtered audit records.
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|in:json,csv',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'action' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'actor_type' => 'nullable|in:customer,staff,system',
        ]);

        /** @var StaffUser $viewer */
        $viewer = $request->user();

        $query = AuditLog::query()
            ->with(['user:id,first_name,last_name,email', 'staffUser:id,first_name,last_name,role'])
            ->latest('created_at')
            ->limit(1000);

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('action', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($userQuery) => $userQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('staffUser', fn ($staffQuery) => $staffQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }
        if (! empty($validated['actor_type'])) {
            match ($validated['actor_type']) {
                'customer' => $query->whereNotNull('user_id'),
                'staff' => $query->whereNotNull('staff_user_id'),
                'system' => $query->whereNull('user_id')->whereNull('staff_user_id'),
            };
        }

        $logs = $query->get();
        $presented = $logs->map(fn (AuditLog $l) => $this->activityLogService->present($l, $viewer))->all();

        $this->auditLogService->log(
            action: 'security.audit_exported',
            previousState: [],
            newState: [
                'format' => $validated['format'],
                'records_count' => count($presented),
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            staffUser: $viewer,
        );

        return ApiResponse::ok([
            'format' => $validated['format'],
            'count' => count($presented),
            'exported_at' => now()->toIso8601String(),
            'records' => $presented,
        ]);
    }
}
