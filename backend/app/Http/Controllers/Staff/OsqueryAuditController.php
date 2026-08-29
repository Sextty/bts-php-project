<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Osquery\OsqueryPresets;
use App\Services\Osquery\OsqueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for Administrator Osquery live telemetry and system inspection.
 * Gated exclusively on staff.role:admin and audit.view.
 */
class OsqueryAuditController extends Controller
{
    public function __construct(private readonly OsqueryService $osquery) {}

    /**
     * Get Osquery status, binary presence, and table definitions.
     */
    public function status(): JsonResponse
    {
        return ApiResponse::ok($this->osquery->getStatus());
    }

    /**
     * Execute custom or predefined Osquery SQL query.
     */
    public function query(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->osquery->execute(
                sql: $validated['sql'],
                actor: $request->user(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return ApiResponse::ok($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_OSQUERY_QUERY',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OSQUERY_EXECUTION_ERROR',
                    'message' => 'Une erreur est survenue lors de l\'exécution de la requête Osquery.',
                ],
            ], 500);
        }
    }

    /**
     * Get curated security audit packs.
     */
    public function presets(): JsonResponse
    {
        return ApiResponse::ok(OsqueryPresets::all());
    }

    /**
     * Run quick automated security scan across ports, users and hardware.
     */
    public function quickAudit(Request $request): JsonResponse
    {
        try {
            $result = $this->osquery->quickAudit(
                actor: $request->user(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return ApiResponse::ok($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OSQUERY_SCAN_ERROR',
                    'message' => 'Erreur lors du scan de sécurité Osquery.',
                ],
            ], 500);
        }
    }
}
