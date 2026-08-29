<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StaffUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail for the Logs & Traffic screen and Security Center.
 */
class ActivityLogService
{
    /** Authentication/OTP traffic — admin & security only: it exposes login patterns and account probing. */
    private const SENSITIVE_ACTION_PREFIXES = ['auth.', 'otp.', 'user.', 'security.', 'osquery.'];

    private readonly DeviceDetectorService $deviceDetector;

    public function __construct(?DeviceDetectorService $deviceDetector = null)
    {
        $this->deviceDetector = $deviceDetector ?? new DeviceDetectorService();
    }

    /**
     * @return array{items: array, meta: array}
     */
    public function paginate(StaffUser $viewer, array $filters = [], int $perPage = 30): array
    {
        $query = AuditLog::query()
            ->with(['user:id,first_name,last_name,email', 'staffUser:id,first_name,last_name,role'])
            ->latest('created_at');

        $this->applyVisibility($query, $viewer);

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%")
                    ->orWhere('user_agent', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    })
                    ->orWhereHas('staffUser', function ($sq) use ($term) {
                        $sq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        if (! empty($filters['actor_type'])) {
            if ($filters['actor_type'] === 'staff') {
                $query->whereNotNull('staff_user_id');
            } elseif ($filters['actor_type'] === 'customer') {
                $query->whereNotNull('user_id');
            } elseif ($filters['actor_type'] === 'system') {
                $query->whereNull('staff_user_id')->whereNull('user_id');
            }
        }

        if (! empty($filters['application_id'])) {
            $query->where('credit_application_id', $filters['application_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $page = $query->paginate($perPage);

        return [
            'items' => collect($page->items())->map(fn (AuditLog $log) => $this->present($log, $viewer))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ];
    }

    /**
     * Traffic summary over the trailing $days.
     */
    public function traffic(StaffUser $viewer, int $days = 14): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $base = fn () => tap(AuditLog::query()->where('created_at', '>=', $from),
            fn ($q) => $this->applyVisibility($q, $viewer));

        $perDay = $base()
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $timeline = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $timeline[] = ['date' => $day, 'events' => (int) ($perDay[$day] ?? 0)];
        }

        $topActions = $base()
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['action' => $r->action, 'count' => (int) $r->total])
            ->all();

        return [
            'days' => $days,
            'total_events' => (int) $base()->count(),
            'active_customers' => (int) $base()->whereNotNull('user_id')->distinct()->count('user_id'),
            'active_staff' => (int) $base()->whereNotNull('staff_user_id')->distinct()->count('staff_user_id'),
            'unique_ips' => ($viewer->isAdmin() || $viewer->isSecurity())
                ? (int) $base()->whereNotNull('ip_address')->distinct()->count('ip_address')
                : null,
            'timeline' => $timeline,
            'top_actions' => $topActions,
        ];
    }

    /** Distinct action names the viewer is allowed to see, for the filter dropdown. */
    public function availableActions(StaffUser $viewer): array
    {
        $query = AuditLog::query()->select('action')->distinct()->orderBy('action');
        $this->applyVisibility($query, $viewer);

        return $query->pluck('action')->all();
    }

    /**
     * Find a single audit log with full details.
     */
    public function find(int $id, StaffUser $viewer): ?array
    {
        $query = AuditLog::query()
            ->with(['user:id,first_name,last_name,email', 'staffUser:id,first_name,last_name,role', 'creditApplication'])
            ->where('id', $id);

        $this->applyVisibility($query, $viewer);

        $log = $query->first();

        return $log ? $this->present($log, $viewer) : null;
    }

    private function applyVisibility($query, StaffUser $viewer): void
    {
        if ($viewer->isBranchRestricted()) {
            $query->whereHas('creditApplication', fn ($q) => $q->where('branch_id', $viewer->branch_id));
        }

        if ($viewer->isAdmin() || $viewer->isSecurity()) {
            return;
        }

        $query->where(function ($q) {
            foreach (self::SENSITIVE_ACTION_PREFIXES as $prefix) {
                $q->where('action', 'not like', $prefix.'%');
            }
        });
    }

    public function present(AuditLog $log, StaffUser $viewer): array
    {
        $row = [
            'id' => $log->id,
            'action' => $log->action,
            'created_at' => $log->created_at?->toIso8601String(),
            'credit_application_id' => $log->credit_application_id,
            'actor' => $this->actor($log),
        ];

        // Device, OS, Location and network details are admin and security only
        if ($viewer->isAdmin() || $viewer->isSecurity()) {
            $device = $this->deviceDetector->detect($log->user_agent, $log->ip_address, $log->new_state ?? $log->previous_state);

            $row['ip_address'] = $log->ip_address;
            $row['user_agent'] = $log->user_agent;
            $row['device'] = $device;
            $row['previous_state'] = $log->previous_state;
            $row['new_state'] = $log->new_state;
        }

        return $row;
    }

    private function actor(AuditLog $log): array
    {
        if ($log->staffUser) {
            return [
                'type' => 'staff',
                'name' => trim($log->staffUser->first_name.' '.$log->staffUser->last_name),
                'role' => $log->staffUser->role,
                'email' => $log->staffUser->email,
            ];
        }

        if ($log->user) {
            return [
                'type' => 'customer',
                'name' => trim($log->user->first_name.' '.$log->user->last_name),
                'role' => null,
                'email' => $log->user->email,
            ];
        }

        return ['type' => 'system', 'name' => 'System', 'role' => null, 'email' => null];
    }
}
