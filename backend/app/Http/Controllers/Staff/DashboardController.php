<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin overview. Admin-only (see the staff.role:admin group in routes/api.php) — it
 * aggregates the whole caseload and per-staff decision counts, which is management information,
 * not something every staff member should read about their colleagues.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

    public function index(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $days = max(7, min($days, 90));

        return ApiResponse::ok($this->stats->build($days));
    }
}
