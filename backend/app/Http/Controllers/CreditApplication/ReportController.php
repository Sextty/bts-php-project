<?php

namespace App\Http\Controllers\CreditApplication;

use App\Events\ReportMessageSent;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportMessageResource;
use App\Models\CreditApplication;
use App\Models\ReportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(CreditApplication $application): JsonResponse
    {
        $this->authorize('view', $application);
        $this->assertReportOpen($application);

        return response()->json([
            'success' => true,
            'data' => ['messages' => ReportMessageResource::collection($application->reportMessages)],
        ]);
    }

    public function store(Request $request, CreditApplication $application): JsonResponse
    {
        $this->authorize('update', $application);
        $this->assertReportOpen($application);

        $validated = $request->validate(['body' => 'required|string|max:2000']);

        $message = $application->reportMessages()->create([
            'sender_type' => ReportMessage::SENDER_CUSTOMER,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        broadcast(new ReportMessageSent($message));

        return response()->json([
            'success' => true,
            'data' => ['message' => new ReportMessageResource($message)],
        ], 201);
    }

    /**
     * The report opens only once the application has been locked after 3 rejections — before
     * that, there's nothing to report and no thread for the customer to write into.
     */
    private function assertReportOpen(CreditApplication $application): void
    {
        if (! $application->hasReached(CreditApplication::STATUS_APPOINTMENT_LOCKED)) {
            throw new ApiException('REPORT_NOT_OPEN', 'This application has no open report.', status: 404);
        }
    }
}
