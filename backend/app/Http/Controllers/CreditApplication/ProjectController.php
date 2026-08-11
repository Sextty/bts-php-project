<?php

namespace App\Http\Controllers\CreditApplication;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditApplication\UpdateProjectRequest;
use App\Http\Resources\CreditApplicationResource;
use App\Models\CreditApplication;
use App\Services\CreditApplicationService;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function __construct(private readonly CreditApplicationService $applications) {}

    public function update(UpdateProjectRequest $request, CreditApplication $application): JsonResponse
    {
        $this->applications->saveProject($application, $request->validated(), $request->ip(), $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['application' => new CreditApplicationResource($application->fresh()->load('project'))],
        ]);
    }
}
