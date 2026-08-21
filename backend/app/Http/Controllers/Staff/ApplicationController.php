<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RejectApplicationRequest;
use App\Http\Resources\CreditApplicationResource;
use App\Http\Responses\ApiResponse;
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
        CreditApplication::STATUS_APPOINTMENT_PROPOSED,
        CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
        CreditApplication::STATUS_APPOINTMENT_LOCKED,
        CreditApplication::STATUS_CANCELLED,
    ];

    public function __construct(private readonly CreditApplicationReviewService $review) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        if ($status !== null && ! in_array($status, self::REVIEW_STATUSES, true)) {
            throw new ApiException(ApiErrorCode::InvalidStatusFilter);
        }

        $applications = CreditApplication::query()
            ->accessibleToStaff($request->user())
            ->whereIn('status', $status ? [$status] : self::REVIEW_STATUSES)
            ->with(['user', 'client', 'creditRequest', 'project', 'branch', 'appointments.branch'])
            ->latest('submitted_at')
            ->paginate(25);

        return ApiResponse::ok([
            'applications' => CreditApplicationResource::collection($applications->items()),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    public function show(Request $request, CreditApplication $application): JsonResponse
    {
        $this->assertStaffCanAccess($request->user(), $application);
        $this->assertUnderReview($application);

        $application->load(['user', 'client', 'creditRequest', 'project', 'documents', 'validationSteps', 'branch', 'appointments.branch']);

        return ApiResponse::ok(['application' => new CreditApplicationResource($application)]);
    }

    public function approve(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $this->assertStaffCanAccess($staff, $application);

        $application = $this->review->staffApprove($application, $staff, $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function reject(RejectApplicationRequest $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $this->assertStaffCanAccess($staff, $application);

        $application = $this->review->staffReject($application, $staff, $request->string('reason'), $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function staffCancel(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        $this->assertStaffCanAccess($staff, $application);

        $application = $this->review->staffCancel($application, $staff, $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function adminApprove(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $admin */
        $admin = $request->user();

        $this->assertStaffCanAccess($admin, $application);

        $application = $this->review->adminApprove($application, $admin, $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function adminReject(RejectApplicationRequest $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $admin */
        $admin = $request->user();

        $this->assertStaffCanAccess($admin, $application);

        $application = $this->review->adminReject($application, $admin, $request->string('reason'), $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    public function adminCancel(Request $request, CreditApplication $application): JsonResponse
    {
        /** @var StaffUser $admin */
        $admin = $request->user();

        $this->assertStaffCanAccess($admin, $application);

        $application = $this->review->staffCancel($application, $admin, $request->ip(), $request->userAgent());

        return $this->respondWith($application);
    }

    /**
     * Branch isolation for a single application (the list side is scopeAccessibleToStaff in
     * index). Unassigned staff and superusers pass by definition; a branch-assigned staff member
     * only reaches applications routed to their branch. Denied loudly (403) rather than silently
     * filtered — a guessed URL must not reveal that the application exists.
     */
    private function assertStaffCanAccess(StaffUser $staff, CreditApplication $application): void
    {
        if (! $staff->canAccessApplication($application)) {
            throw new ApiException(ApiErrorCode::Forbidden, 'This application is not handled by your branch.');
        }
    }

    /** Keeps staff/admin from reaching into an application still in the customer's own draft flow. */
    private function assertUnderReview(CreditApplication $application): void
    {
        if (! in_array($application->status, self::REVIEW_STATUSES, true)) {
            throw new ApiException(ApiErrorCode::NotUnderReview);
        }
    }

    private function respondWith(CreditApplication $application): JsonResponse
    {
        $application->load(['user', 'client', 'creditRequest', 'project', 'branch', 'appointments.branch']);

        return ApiResponse::ok(['application' => new CreditApplicationResource($application)]);
    }
}
