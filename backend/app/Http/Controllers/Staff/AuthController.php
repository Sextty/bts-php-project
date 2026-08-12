<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StaffLoginRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\StaffUser;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Internal employee auth — plain email+password, no OTP. Staff/admin accounts have no phone or
 * Telegram link (they're bootstrapped via `php artisan staff:make`, not the customer signup
 * flow), so the OTP machinery built for customers doesn't apply here.
 */
class AuthController extends Controller
{
    /** Same timing-safe-against-unknown-accounts reasoning as Auth\LoginController::DUMMY_HASH. */
    private const DUMMY_HASH = '$2y$12$8pS8v0m3F1qFQhH7QeJmMOa1n9v9wq4o1QeJmMOa1n9v9wq4o1QeJ';

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function login(StaffLoginRequest $request): JsonResponse
    {
        $staff = StaffUser::where('email', $request->string('email'))->first();

        if ($staff) {
            $passwordValid = Hash::check($request->string('password'), $staff->password);
        } else {
            Hash::check($request->string('password'), self::DUMMY_HASH);
            $passwordValid = false;
        }

        if (! $passwordValid) {
            throw new ApiException('INVALID_CREDENTIALS', 'The email or password is incorrect.', status: 401);
        }

        if ($staff->status !== 'active') {
            throw new ApiException('ACCOUNT_SUSPENDED', 'This account is suspended.', status: 403);
        }

        $token = $staff->createToken('staff-api')->plainTextToken;

        $this->auditLog->log('staff.login', ipAddress: $request->ip(), userAgent: $request->userAgent(), staffUser: $staff);

        return response()->json([
            'success' => true,
            'data' => ['access_token' => $token, 'staff_user' => new StaffUserResource($staff)],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();
        $staff->currentAccessToken()->delete();

        $this->auditLog->log('staff.logout', ipAddress: $request->ip(), userAgent: $request->userAgent(), staffUser: $staff);

        return response()->json(['success' => true, 'data' => null]);
    }
}
