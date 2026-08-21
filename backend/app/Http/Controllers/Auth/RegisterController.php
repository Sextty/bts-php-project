<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CustomerAuthService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    private const PURPOSE = 'registration';

    public function __construct(
        private readonly CustomerAuthService $auth,
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

        $preAuthToken = $this->auth->beginOtpChallenge($user, self::PURPOSE, $request->ip(), $request->userAgent());

        return ApiResponse::created(['user_id' => $user->id, 'pre_auth_token' => $preAuthToken]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->auth->completeOtpChallenge(
            $request->string('pre_auth_token'),
            self::PURPOSE,
            $request->string('otp_code'),
            self::PURPOSE,
            $request->ip(),
            $request->userAgent(),
        );

        // Phone ownership is proven by the OTP that just verified — record it so future
        // sessions (and the Google flow) can rely on it without another round trip.
        $result['user']->forceFill(['phone_verified_at' => now()])->save();

        return ApiResponse::ok(['access_token' => $result['token'], 'user' => new UserResource($result['user'])]);
    }
}
