<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $this->auditLog->log('auth.logout', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

        return ApiResponse::noContent();
    }
}
