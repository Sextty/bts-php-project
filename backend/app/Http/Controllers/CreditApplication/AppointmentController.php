<?php

namespace App\Http\Controllers\CreditApplication;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
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
            throw new ApiException('NO_APPOINTMENT', 'No appointment has been proposed for this application yet.', status: 404);
        }

        $appointment->load('branch');

        return response()->json([
            'success' => true,
            'data' => ['appointment' => new AppointmentResource($appointment)],
        ]);
    }

    public function accept(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $appointment = $this->currentAppointmentOr404($application);
        $appointment = $this->scheduling->accept($application, $appointment, $request->ip(), $request->userAgent());
        $appointment->load('branch');

        return response()->json([
            'success' => true,
            'data' => ['appointment' => new AppointmentResource($appointment)],
        ]);
    }

    public function reject(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);

        $appointment = $this->currentAppointmentOr404($application);
        $this->scheduling->reject($application, $appointment, $request->ip(), $request->userAgent());

        $application = $application->fresh();

        // latestAppointment() returns the newest row regardless of status, so on the 3rd
        // rejection (locked, no new proposal made) it would return the very appointment that
        // was just rejected — the frontend needs `appointment: null` here to mean "no new
        // proposal, you're locked," not the rejected one it should already know about. Gating
        // on the application's status (not just "does a row exist") is what makes that
        // distinction — caught by a test asserting `appointment: null` on the 3rd rejection.
        $next = $application->status === CreditApplication::STATUS_APPOINTMENT_PROPOSED
            ? $application->latestAppointment()
            : null;
        $next?->load('branch');

        return response()->json([
            'success' => true,
            'data' => [
                'application_status' => $application->status,
                'appointment' => $next ? new AppointmentResource($next) : null,
            ],
        ]);
    }

    private function currentAppointmentOr404(CreditApplication $application): Appointment
    {
        $appointment = $application->latestAppointment();

        if (! $appointment) {
            throw new ApiException('NO_APPOINTMENT', 'No appointment has been proposed for this application yet.', status: 404);
        }

        return $appointment;
    }
}
