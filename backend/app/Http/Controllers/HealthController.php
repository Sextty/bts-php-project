<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public readiness endpoint for load balancers and uptime probes. Deliberately
 * unauthenticated (a monitor can't hold a customer token), read-only, and leaking
 * nothing but ok/failed booleans and a short detail string — no exception
 * messages, no configuration, no credentials. 200 while every dependency the app
 * depends on is healthy, 503 the moment one of them is not.
 */
class HealthController extends Controller
{
    /** Fast liveness probe: it intentionally does not contact any dependency. */
    public function live(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['status' => 'ok'],
        ]);
    }

    public function __invoke(Request $request, HealthCheckService $health): JsonResponse
    {
        $report = $health->check();

        return response()->json([
            'success' => true,
            'data' => $report,
        ], $report['status'] === 'ok' ? 200 : 503);
    }
}
