<?php

namespace App\Http\Controllers\Staff;

use App\Events\ReportMessageSent;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreditApplicationResource;
use App\Http\Resources\ReportMessageResource;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Any staff role (not admin-only) can see and reply here — same reasoning as the rest of the
 * staff dashboard: a locked application needs a human to pick it up quickly, and gatekeeping that
 * behind admin-only would just slow down the customer's wait for a reply.
 */
class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        $applications = CreditApplication::query()
            ->where('status', CreditApplication::STATUS_APPOINTMENT_LOCKED)
            ->with(['user', 'client', 'creditRequest'])
            ->withCount('reportMessages')
            ->latest('updated_at')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => [
                'applications' => CreditApplicationResource::collection($applications->items()),
                'meta' => [
                    'current_page' => $applications->currentPage(),
                    'last_page' => $applications->lastPage(),
                    'total' => $applications->total(),
                ],
            ],
        ]);
    }

    public function show(CreditApplication $application): JsonResponse
    {
        $this->assertLocked($application);

        return response()->json([
            'success' => true,
            'data' => ['messages' => ReportMessageResource::collection($application->reportMessages)],
        ]);
    }

    public function store(Request $request, CreditApplication $application): JsonResponse
    {
        $this->assertLocked($application);

        $validated = $request->validate(['body' => 'required|string|max:2000']);

        /** @var StaffUser $staff */
        $staff = $request->user();

        $message = $application->reportMessages()->create([
            'sender_type' => ReportMessage::SENDER_STAFF,
            'staff_user_id' => $staff->id,
            'body' => $validated['body'],
        ]);

        broadcast(new ReportMessageSent($message));

        return response()->json([
            'success' => true,
            'data' => ['message' => new ReportMessageResource($message)],
        ], 201);
    }

    private function assertLocked(CreditApplication $application): void
    {
        if (! $application->hasReached(CreditApplication::STATUS_APPOINTMENT_LOCKED)) {
            throw new ApiException('REPORT_NOT_OPEN', 'This application has no open report.', status: 404);
        }
    }
}
