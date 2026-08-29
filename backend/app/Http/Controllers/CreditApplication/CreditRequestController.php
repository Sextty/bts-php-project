<?php

namespace App\Http\Controllers\CreditApplication;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditApplication\UpdateCreditRequestRequest;
use App\Http\Resources\CreditApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\CreditApplication;
use App\Services\CreditApplicationService;
use Illuminate\Http\JsonResponse;

class CreditRequestController extends Controller
{
    public function __construct(private readonly CreditApplicationService $applications) {}

    public function update(UpdateCreditRequestRequest $request, CreditApplication $application): JsonResponse
    {
        $this->applications->saveCreditRequest($application, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return ApiResponse::ok(['application' => new CreditApplicationResource($application->fresh()->load('creditRequest'))]);
    }
}
