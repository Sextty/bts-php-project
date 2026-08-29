<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\StaffUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentManagementController extends Controller
{
    /**
     * List all appointments with full client, application, and agency details.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $query = Appointment::query()
            ->with([
                'branch',
                'creditApplication.user',
                'creditApplication.client',
                'creditApplication.creditRequest',
            ]);

        // Branch-isolation scoping for branch-restricted staff
        if ($staff instanceof StaffUser && $staff->isBranchRestricted()) {
            $query->where('branch_id', $staff->branch_id);
        } elseif ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        // Status filter
        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // Date filters
        if ($date = $request->query('date')) {
            if ($date === 'today') {
                $query->whereDate('scheduled_date', Carbon::today());
            } elseif ($date === 'upcoming') {
                $query->whereDate('scheduled_date', '>=', Carbon::today());
            } elseif ($date === 'past') {
                $query->whereDate('scheduled_date', '<', Carbon::today());
            } else {
                $query->whereDate('scheduled_date', $date);
            }
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('scheduled_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('scheduled_date', '<=', $dateTo);
        }

        // Search filter (Client name, CIN, Phone, Application Number)
        if ($search = trim($request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('creditApplication', function ($appQ) use ($search) {
                    $appQ->where('application_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQ) use ($search) {
                            $userQ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('client', function ($clientQ) use ($search) {
                            $clientQ->where('nom', 'like', "%{$search}%")
                                ->orWhere('prenom', 'like', "%{$search}%")
                                ->orWhere('numero_pid', 'like', "%{$search}%");
                        });
                });
            });
        }

        // Order by
        $sortBy = $request->query('sort_by', 'scheduled_date');
        $sortDir = strtolower($request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['scheduled_date', 'created_at', 'status', 'attempt_number'])) {
            $query->orderBy($sortBy, $sortDir)->orderBy('scheduled_time', $sortDir);
        } else {
            $query->orderBy('scheduled_date', 'desc')->orderBy('scheduled_time', 'asc');
        }

        $perPage = min(max((int) $request->query('per_page', 20), 5), 100);
        $paginator = $query->paginate($perPage);

        // Global / scoped summary statistics
        $statsBase = Appointment::query();
        if ($staff instanceof StaffUser && $staff->isBranchRestricted()) {
            $statsBase->where('branch_id', $staff->branch_id);
        }

        $stats = [
            'total' => (clone $statsBase)->count(),
            'accepted' => (clone $statsBase)->where('status', Appointment::STATUS_ACCEPTED)->count(),
            'proposed' => (clone $statsBase)->where('status', Appointment::STATUS_PROPOSED)->count(),
            'rejected' => (clone $statsBase)->where('status', Appointment::STATUS_REJECTED)->count(),
            'today' => (clone $statsBase)->whereDate('scheduled_date', Carbon::today())->count(),
            'upcoming' => (clone $statsBase)->whereDate('scheduled_date', '>=', Carbon::today())->count(),
        ];

        return ApiResponse::ok([
            'appointments' => $paginator->map(function (Appointment $appointment) {
                $app = $appointment->creditApplication;
                $user = $app?->user;
                $client = $app?->client;
                $credit = $app?->creditRequest;
                $branch = $appointment->branch;

                $clientName = trim(($client?->prenom ?? $user?->first_name ?? '').' '.($client?->nom ?? $user?->last_name ?? ''));
                if (empty($clientName)) {
                    $clientName = 'Client #'.($user?->id ?? 'Anonyme');
                }

                return [
                    'id' => $appointment->id,
                    'attempt_number' => $appointment->attempt_number,
                    'max_attempts' => Appointment::MAX_ATTEMPTS,
                    'scheduled_date' => $appointment->scheduled_date?->format('Y-m-d'),
                    'scheduled_time' => $appointment->scheduled_time,
                    'time_formatted' => $appointment->scheduled_time ? substr($appointment->scheduled_time, 0, 5) : '',
                    'status' => $appointment->status,
                    'decided_at' => $appointment->decided_at?->toIso8601String(),
                    'is_auto_scheduled_future' => (bool) $appointment->is_auto_scheduled_future,
                    'created_at' => $appointment->created_at?->toIso8601String(),
                    'application' => $app ? [
                        'id' => $app->id,
                        'application_number' => $app->application_number ?? "DEM-{$app->id}",
                        'status' => $app->status,
                        'amount' => $credit?->montant_credit_demande,
                        'is_report_open' => $app->isReportOpen(),
                        'is_report_closed' => $app->isReportClosed(),
                    ] : null,
                    'client' => [
                        'id' => $user?->id,
                        'name' => $clientName,
                        'first_name' => $client?->prenom ?? $user?->first_name,
                        'last_name' => $client?->nom ?? $user?->last_name,
                        'email' => $user?->email,
                        'phone' => $user?->phone,
                        'cin' => $client?->numero_pid,
                        'cin_type' => $client?->type_pid ?? 'cin',
                        'profession' => $client?->profession,
                    ],
                    'branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'address' => $branch->address,
                        'phone' => $branch->phone,
                        'fax' => $branch->fax,
                        'ville' => $branch->ville,
                        'latitude' => $branch->latitude,
                        'longitude' => $branch->longitude,
                        'google_maps_url' => $branch->googleMapsUrl(),
                    ] : null,
                ];
            }),
            'stats' => $stats,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Get detailed overview of all 28 BTS bank branches with metrics.
     */
    public function branchesOverview(Request $request): JsonResponse
    {
        $today = Carbon::today();
        /** @var StaffUser $staff */
        $staff = $request->user();

        $branches = Branch::query()
            ->when($staff->isBranchRestricted(), function ($query) use ($staff) {
                if ($staff->branch_id === null) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereKey($staff->branch_id);
            })
            ->withCount([
                'appointments',
                'appointments as accepted_appointments_count' => function ($q) {
                    $q->where('status', Appointment::STATUS_ACCEPTED);
                },
                'appointments as proposed_appointments_count' => function ($q) {
                    $q->where('status', Appointment::STATUS_PROPOSED);
                },
                'appointments as today_appointments_count' => function ($q) use ($today) {
                    $q->whereDate('scheduled_date', $today);
                },
                'appointments as upcoming_appointments_count' => function ($q) use ($today) {
                    $q->whereDate('scheduled_date', '>=', $today);
                },
            ])
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return ApiResponse::ok([
            'branches' => $branches->map(function (Branch $branch) {
                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'ville' => $branch->ville,
                    'delegation' => $branch->delegation,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                    'fax' => $branch->fax,
                    'opening_hours' => $branch->opening_hours ?? '08:00 - 16:30',
                    'daily_capacity' => $branch->daily_capacity,
                    'slot_times' => $branch->slotTimes(),
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                    'google_maps_url' => $branch->googleMapsUrl(),
                    'is_default' => (bool) $branch->is_default,
                    'appointments_count' => $branch->appointments_count,
                    'accepted_appointments_count' => $branch->accepted_appointments_count,
                    'proposed_appointments_count' => $branch->proposed_appointments_count,
                    'today_appointments_count' => $branch->today_appointments_count,
                    'upcoming_appointments_count' => $branch->upcoming_appointments_count,
                ];
            }),
            'total_branches' => $branches->count(),
        ]);
    }
}
