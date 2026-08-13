<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OtpService;
use App\Services\PreAuthTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    private const PURPOSE = 'login';

    /** A real bcrypt hash of an unguessable value — never actually matched, only timed against. */
    private const DUMMY_HASH = '$2y$12$8pS8v0m3F1qFQhH7QeJmMOa1n9v9wq4o1QeJmMOa1n9v9wq4o1QeJ';

    public function __construct(
        private readonly OtpService $otp,
        private readonly PreAuthTokenService $preAuth,
        private readonly AuditLogService $auditLog,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->string('identifier');
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        // Always run a Hash::check, even for an unknown account or a Google-only account with no
        // local password — so response timing can't be used to enumerate valid accounts. The
        // platform being replaced called this equalizeVerificationTiming(); same reasoning here.
        if ($user && $user->password) {
            $passwordValid = Hash::check($request->string('password'), $user->password);
        } else {
            Hash::check($request->string('password'), self::DUMMY_HASH);
            $passwordValid = false;
        }

        if (! $passwordValid) {
            $this->auditLog->log('auth.login.failed', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

            throw new ApiException('INVALID_CREDENTIALS', 'The email/phone or password is incorrect.', status: 401);
        }

        if ($user->status !== 'active') {
            $this->auditLog->log('auth.login.rejected', $user, newState: ['reason' => 'suspended'], ipAddress: $request->ip(), userAgent: $request->userAgent());

            throw new ApiException('ACCOUNT_SUSPENDED', 'This account is suspended.', status: 403);
        }

        if ($this->otp->isRequestThrottled($user, self::PURPOSE)) {
            throw new ApiException('RATE_LIMITED', 'Please wait before requesting another code.', status: 429);
        }

        $this->auditLog->log('auth.login.password_verified', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

        // Only a 5-minute, single-purpose pre_auth_token is issued here — never an access token.
        // A correct password alone must never reach an authenticated endpoint.
        $preAuthToken = $this->preAuth->issue($user, self::PURPOSE);

        $this->otp->generateAndSend($user, self::PURPOSE, $request->ip(), $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['pre_auth_token' => $preAuthToken],
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->preAuth->resolve($request->string('pre_auth_token'), self::PURPOSE);

        $this->otp->verify($user, self::PURPOSE, $request->string('otp_code'), $request->ip(), $request->userAgent());

        $this->preAuth->invalidate($request->string('pre_auth_token'));

        $token = $user->createToken('api')->plainTextToken;

        $this->auditLog->log('auth.session.created', $user, newState: ['via' => self::PURPOSE], ipAddress: $request->ip(), userAgent: $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['access_token' => $token, 'user' => new UserResource($user)],
        ]);
    }
}
