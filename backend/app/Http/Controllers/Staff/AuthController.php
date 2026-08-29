<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StaffLoginRequest;
use App\Http\Resources\StaffUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\StaffUser;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Internal employee auth — plain email+password.
 *
 * Staff, security and admin have separate entrances:
 * - `/staff/login` accepts `staff` accounts
 * - `/staff/admin/login` accepts `admin` and `super_admin` accounts
 * - `/security/auth/login` accepts `security`, `admin` and `super_admin` accounts
 */
class AuthController extends Controller
{
    /** Same timing-safe-against-unknown-accounts reasoning as Auth\LoginController::DUMMY_HASH. */
    private const DUMMY_HASH = '$2y$12$8pS8v0m3F1qFQhH7QeJmMOa1n9v9wq4o1QeJmMOa1n9v9wq4o1QeJ';

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function login(StaffLoginRequest $request): JsonResponse
    {
        return $this->attemptLogin($request, 'staff');
    }

    public function adminLogin(StaffLoginRequest $request): JsonResponse
    {
        return $this->attemptLogin($request, ['admin', 'super_admin']);
    }

    public function securityLogin(StaffLoginRequest $request): JsonResponse
    {
        return $this->attemptLogin($request, ['security', 'admin', 'super_admin']);
    }

    /**
     * @param string|array<string> $role
     */
    private function attemptLogin(StaffLoginRequest $request, string|array $role): JsonResponse
    {
        $staff = StaffUser::where('email', $request->string('email'))->first();

        if ($staff) {
            $passwordValid = Hash::check($request->string('password'), $staff->password);
        } else {
            Hash::check($request->string('password'), self::DUMMY_HASH);
            $passwordValid = false;
        }

        if (! $passwordValid) {
            throw new ApiException(ApiErrorCode::InvalidCredentials, 'The email or password is incorrect.');
        }

        $allowedRoles = (array) $role;
        if (! in_array($staff->role, $allowedRoles, true)) {
            $required = implode(' or ', $allowedRoles);
            throw new ApiException(ApiErrorCode::Forbidden, "This account does not have the {$required} role.");
        }

        if ($staff->status !== 'active') {
            throw new ApiException(ApiErrorCode::AccountSuspended);
        }

        $token = $staff->createToken('staff-api')->plainTextToken;

        $this->auditLog->log('staff.login', ipAddress: $request->ip(), userAgent: $request->userAgent(), staffUser: $staff);

        return ApiResponse::ok(['access_token' => $token, 'staff_user' => new StaffUserResource($staff)]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();
        $staff->currentAccessToken()->delete();

        $this->auditLog->log('staff.logout', ipAddress: $request->ip(), userAgent: $request->userAgent(), staffUser: $staff);

        return ApiResponse::noContent();
    }
}
