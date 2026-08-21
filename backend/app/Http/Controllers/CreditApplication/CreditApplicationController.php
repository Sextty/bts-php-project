<?php

namespace App\Http\Controllers\CreditApplication;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreditApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\CreditApplication;
use App\Services\CreditApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditApplicationController extends Controller
{
    public function __construct(private readonly CreditApplicationService $applications) {}

    public function index(Request $request): JsonResponse
    {
        $applications = $request->user()->creditApplications()->with(['creditRequest', 'appointments.branch'])->latest()->get();

        return ApiResponse::ok(['applications' => CreditApplicationResource::collection($applications)]);
    }

    public function store(Request $request): JsonResponse
    {
        $application = $this->applications->create($request->user(), $request->ip(), $request->userAgent());

        return ApiResponse::created(['application' => new CreditApplicationResource($application)]);
    }

    public function show(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('view', $application);

        $application->load(['client', 'creditRequest', 'project', 'documents', 'validationSteps']);

        return ApiResponse::ok(['application' => new CreditApplicationResource($application)]);
    }

    public function submit(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $application = $this->applications->submit($application, $request->user(), $request->ip(), $request->userAgent());
        // ->submit() returns a bare fresh() instance with no relations loaded — see the same
        // note in ValidationController::loadFull().
        $application->load(['client', 'creditRequest', 'project', 'documents', 'validationSteps']);

        return ApiResponse::ok(['application' => new CreditApplicationResource($application)]);
    }

    public function cancel(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $application = $this->applications->cancel($application, $request->user(), $request->ip(), $request->userAgent());

        return ApiResponse::ok(['application' => new CreditApplicationResource($application)]);
    }
}
