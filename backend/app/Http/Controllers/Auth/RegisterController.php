<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\SmsProviderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OtpService;
use App\Services\PreAuthTokenService;
use App\Services\TelegramLinkService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    private const PURPOSE = 'registration';

    public function __construct(
        private readonly OtpService $otp,
        private readonly PreAuthTokenService $preAuth,
        private readonly AuditLogService $auditLog,
        private readonly SmsProviderInterface $otpChannel,
        private readonly TelegramLinkService $telegramLink,
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

        // Telegram cannot message an account that has never opened a conversation with the bot,
        // so a brand-new user has nowhere to receive a code yet. Rather than burn an OTP and fail,
        // hand back the deep link and let the client walk them through the one-time handshake;
        // the code is dispatched by TelegramLinkController the moment they press Start.
        if (! $this->otpChannel->canReach($user)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'pre_auth_token' => $preAuthToken,
                    'requires_telegram_link' => true,
                    'telegram_link_url' => $this->telegramLink->deepLinkFor($user),
                ],
            ], 201);
        }

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
