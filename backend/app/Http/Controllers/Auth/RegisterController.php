<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OtpService;
use App\Services\PreAuthTokenService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    private const PURPOSE = 'registration';

    public function __construct(
        private readonly OtpService $otp,
        private readonly PreAuthTokenService $preAuth,
        private readonly AuditLogService $auditLog,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'first_name' => $request->string('first_name'),
            'last_name' => $request->string('last_name'),
            'email' => $request->string('email'),
            'phone' => $request->string('phone'),
            'password' => $request->string('password'),
            'auth_provider' => 'password',
            'status' => 'active',
        ]);

        $this->auditLog->log('user.registered', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

        $preAuthToken = $this->preAuth->issue($user, self::PURPOSE);

        $this->otp->generateAndSend($user, self::PURPOSE, $request->ip(), $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['user_id' => $user->id, 'pre_auth_token' => $preAuthToken],
        ], 201);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->preAuth->resolve($request->string('pre_auth_token'), self::PURPOSE);

        $this->otp->verify($user, self::PURPOSE, $request->string('otp_code'), $request->ip(), $request->userAgent());

        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->preAuth->invalidate($request->string('pre_auth_token'));

        $token = $user->createToken('api')->plainTextToken;

        $this->auditLog->log('auth.session.created', $user, newState: ['via' => self::PURPOSE], ipAddress: $request->ip(), userAgent: $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['access_token' => $token, 'user' => new UserResource($user)],
        ]);
    }
}
