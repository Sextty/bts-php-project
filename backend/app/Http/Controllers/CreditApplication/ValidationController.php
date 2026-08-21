<?php

namespace App\Http\Controllers\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreditApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\CreditApplication;
use App\Services\CreditApplicationService;
use App\Services\CreditApplicationValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidationController extends Controller
{
    public function __construct(
        private readonly CreditApplicationValidationService $validation,
        private readonly CreditApplicationService $applications,
    ) {}

    public function validationOne(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);
        $this->applications->assertEditable($application);

        if (! $application->hasReached(CreditApplication::STATUS_READY_FOR_VALIDATION_1)) {
            throw new ApiException(ApiErrorCode::StepsIncomplete);
        }

        $errors = $this->validation->runValidationOne($application, $request->user(), $request->ip(), $request->userAgent());

        if ($errors) {
            // The one hand-built error response in the app: business-validation output with a
            // per-document `errors` payload, which the single-code envelope doesn't fit.
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_1_FAILED', 'message' => 'Validation failed.', 'errors' => $errors, 'request_id' => $request->attributes->get('request_id')],
            ], 422);
        }

        return ApiResponse::ok(['application' => new CreditApplicationResource($this->loadFull($application))]);
    }

    public function validationTwo(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $application = $this->applications->confirmValidationTwoAndLock($application, $request->user(), $request->ip(), $request->userAgent());

        return ApiResponse::ok(['application' => new CreditApplicationResource($this->loadFull($application))]);
    }

    /**
     * ->fresh() (used throughout CreditApplicationService to return post-transition state)
     * drops previously eager-loaded relations, so every response that goes through it needs
     * this before CreditApplicationResource's whenLoaded() calls will emit anything — caught
     * live: the validation review page's "ready for validation" check silently went blank
     * after validation-1 because client/creditRequest/project vanished from the JSON.
     */
    private function loadFull(CreditApplication $application): CreditApplication
    {
        return $application->fresh(['client', 'creditRequest', 'project', 'documents', 'validationSteps', 'branch', 'appointments.branch']);
    }
}
