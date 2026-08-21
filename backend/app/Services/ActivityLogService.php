<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StaffUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail for the shared Logs & Traffic screen.
 *
 * Both staff and admin reach this screen, but they must not see the same thing. A regular staff
 * member needs to follow what happened to a file; they have no business reason to see customers'
 * IP addresses, their devices, or authentication events like OTP requests. So the narrowing
 * happens *here*, on the server: the restricted rows are never selected and the sensitive columns
 * are never serialized. Hiding them in the frontend would leave the data one devtools tab away.
 */
class ActivityLogService
{
    /** Authentication/OTP traffic — admin-only: it exposes login patterns and account probing. */
    private const SENSITIVE_ACTION_PREFIXES = ['auth.', 'otp.', 'user.'];

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
     * Traffic summary over the trailing $days. Same visibility rules as the log list — an admin's
     * totals include auth events, a staff member's don't, so the two roles never see numbers that
     * imply rows they're not allowed to read.
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
            // Unique IPs is a security signal (where are these sessions coming from), so it stays
            // admin-only along with the addresses themselves.
            'unique_ips' => $viewer->isAdmin()
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

    /** Non-admins never see authentication/OTP rows at all. Branch-assigned staff additionally
     * see nothing but logs about their own branch's applications — an audit trail is scoped the
     * same way the files it traces are. */
    private function applyVisibility($query, StaffUser $viewer): void
    {
        if ($viewer->isBranchRestricted()) {
            $query->whereHas('creditApplication', fn ($q) => $q->where('branch_id', $viewer->branch_id));
        }

        if ($viewer->isAdmin()) {
            return;
        }

        $query->where(function ($q) {
            foreach (self::SENSITIVE_ACTION_PREFIXES as $prefix) {
                $q->where('action', 'not like', $prefix.'%');
            }
        });
    }

    private function present(AuditLog $log, StaffUser $viewer): array
    {
        $row = [
            'id' => $log->id,
            'action' => $log->action,
            'created_at' => $log->created_at?->toIso8601String(),
            'credit_application_id' => $log->credit_application_id,
            'actor' => $this->actor($log),
        ];

        // Device and network details are admin-only; see the class docblock.
        if ($viewer->isAdmin()) {
            $row['ip_address'] = $log->ip_address;
            $row['user_agent'] = $log->user_agent;
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
            ];
        }

        if ($log->user) {
            return [
                'type' => 'customer',
                'name' => trim($log->user->first_name.' '.$log->user->last_name),
                'role' => null,
            ];
        }

        // Neither actor survived (e.g. the account was deleted and the FK nulled), so the entry is
        // attributed to the system rather than dropped — the audit trail keeps the event either way.
        return ['type' => 'system', 'name' => 'System', 'role' => null];
    }
}
