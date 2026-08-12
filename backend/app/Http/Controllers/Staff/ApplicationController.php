<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RejectApplicationRequest;
use App\Http\Resources\CreditApplicationResource;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Services\CreditApplicationReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff/admin review surface. Every route is behind `auth:sanctum` + `staff` (see
 * routes/api.php), and the admin-only actions additionally require `staff.role:admin` — staff and
 * admin share this controller since the request/response shapes are identical, only the allowed
 * transition differs, which CreditApplicationReviewService already enforces per-method.
 */
class ApplicationController extends Controller
{
    private const REVIEW_STATUSES = [
        CreditApplication::STATUS_SUBMITTED,
        CreditApplication::STATUS_STAFF_APPROVED,
        CreditApplication::STATUS_STAFF_REJECTED,
        CreditApplication::STATUS_APPROVED,
        CreditApplication::STATUS_REJECTED,
    ];

    public function __construct(private readonly CreditApplicationReviewService $review) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        if ($status !== null && ! in_array($status, self::REVIEW_STATUSES, true)) {
            throw new ApiException('INVALID_STATUS_FILTER', 'Unknown status filter.', status: 422);
        }

        $applications = CreditApplication::query()
            ->whereIn('status', $status ? [$status] : self::REVIEW_STATUSES)
            ->with(['user', 'client', 'creditRequest', 'project'])
            ->latest('submitted_at')
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
        $this->assertUnderReview($application);

        $application->load(['user', 'client', 'creditRequest', 'project', 'documents', 'validationSteps']);

        return response()->json([
            'success' => true,
            'data' => ['application' => new CreditApplicationResource($application)],
        ]);
    }

    public function approve(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $application = $this->review->staffApprove($application, $staff, $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function reject(RejectApplicationRequest $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $application = $this->review->staffReject($application, $staff, $request->string('reason'), $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function adminApprove(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $admin */
        $admin = $request->user();

        $application = $this->review->adminApprove($application, $admin, $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function adminReject(RejectApplicationRequest $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $admin */
        $admin = $request->user();

        $application = $this->review->adminReject($application, $admin, $request->string('reason'), $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    /** Keeps staff/admin from reaching into an application still in the customer's own draft flow. */
    private function assertUnderReview(CreditApplication $application): void
    {
        if (! in_array($application->status, self::REVIEW_STATUSES, true)) {
            throw new ApiException('NOT_UNDER_REVIEW', 'This application has not been submitted yet.', status: 404);
        }
    }

    private function respondWith(CreditApplication $application): JsonResponse
    {
        $application->load(['user', 'client', 'creditRequest', 'project']);

        return response()->json([
            'success' => true,
            'data' => ['application' => new CreditApplicationResource($application)],
        ]);
    }
}
