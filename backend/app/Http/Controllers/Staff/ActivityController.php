<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Logs & traffic, shared by staff and admin. Both roles hit the same routes; what differs is what
 * comes back, and that difference is decided in ActivityLogService against the authenticated
 * StaffUser — never from a client-supplied parameter.
 */
class ActivityController extends Controller
{
    public function __construct(private readonly ActivityLogService $activity) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['action', 'application_id', 'from', 'to']);
        $perPage = max(10, min((int) $request->query('per_page', 30), 100));

        $result = $this->activity->paginate($request->user(), $filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $result['items'],
                'meta' => $result['meta'],
                'available_actions' => $this->activity->availableActions($request->user()),
            ],
        ]);
    }

    public function traffic(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 14);
        $days = max(7, min($days, 90));

        return response()->json([
            'success' => true,
            'data' => $this->activity->traffic($request->user(), $days),
        ]);
    }
}
