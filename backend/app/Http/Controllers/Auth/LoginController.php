<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\CustomerAuthService;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    private const PURPOSE = 'login';

    public function __construct(private readonly CustomerAuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $preAuthToken = $this->auth->authenticateWithPassword(
            $request->string('identifier'),
            $request->string('password'),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::ok(['pre_auth_token' => $preAuthToken]);
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

        return ApiResponse::ok(['access_token' => $result['token'], 'user' => new UserResource($result['user'])]);
    }
}
