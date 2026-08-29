<?php

namespace App\Http\Controllers\CreditApplication;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\CreditApplication;
use App\Services\AppointmentSchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentSchedulingService $scheduling) {}

    public function show(CreditApplication $application): JsonResponse
    {
        $this->authorize('view', $application);

        $appointment = $application->latestAppointment();

        if (! $appointment) {
            throw new ApiException(ApiErrorCode::NoAppointment);
        }

        $appointment->load('branch');

        return ApiResponse::ok(['appointment' => new AppointmentResource($appointment)]);
    }

    public function accept(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $appointment = $this->currentAppointmentOr404($application);
        $appointment = $this->scheduling->accept($application, $appointment, $request->user(), $request->ip(), $request->userAgent());
        $appointment->load('branch');

        return ApiResponse::ok(['appointment' => new AppointmentResource($appointment)]);
    }

    public function reject(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $appointment = $this->currentAppointmentOr404($application);
        $this->scheduling->reject($application, $appointment, $request->user(), $request->ip(), $request->userAgent());

        $application = $application->fresh();

        $next = $application->latestAppointment();
        $next?->load('branch');

        return ApiResponse::ok([
            'application_status' => $application->status,
            'appointment' => $next ? new AppointmentResource($next) : null,
        ]);
    }

    private function currentAppointmentOr404(CreditApplication $application): Appointment
    {
        $appointment = $application->latestAppointment();

        if (! $appointment) {
            throw new ApiException(ApiErrorCode::NoAppointment);
        }

        return $appointment;
    }
}
