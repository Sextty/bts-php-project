<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Http\Requests\Auth\GoogleSetPhoneRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Google\GoogleTokenVerifier;
use App\Services\OtpService;
use App\Services\PreAuthTokenService;
use Illuminate\Http\JsonResponse;

/**
 * Google Authentication -> check phone verification -> [request phone -> ] send OTP -> verify OTP
 * -> authenticated. The explicit fix versus the platform being replaced: that system let a first
 * -time Google sign-in skip phone verification entirely ("Google's own authentication is the
 * second factor" — its own code comment). Here, phone_verified_at gates token issuance
 * unconditionally, Google or password, every time.
 */
class GoogleAuthController extends Controller
{
    private const PURPOSE = 'registration';

    public function __construct(
        private readonly GoogleTokenVerifier $verifier,
        private readonly OtpService $otp,
        private readonly PreAuthTokenService $preAuth,
        private readonly AuditLogService $auditLog,
    ) {}

    public function authenticate(GoogleAuthRequest $request): JsonResponse
    {
        $identity = $this->verifier->verify($request->string('google_id_token'));

        $user = User::where('google_id', $identity->sub)->first();

        if (! $user) {
            // Email must be Google-verified before any linking/creation — otherwise an
            // unverified look-alike Google account could claim someone else's address.
            if (! $identity->emailVerified) {
                $this->auditLog->log('auth.google.rejected', null, newState: ['reason' => 'email_not_verified'], ipAddress: $request->ip(), userAgent: $request->userAgent());

                throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed.', status: 401);
            }

            $existingByEmail = $identity->email ? User::where('email', $identity->email)->first() : null;

            if ($existingByEmail) {
                $existingByEmail->forceFill(['google_id' => $identity->sub])->save();
                $user = $existingByEmail;
                $this->auditLog->log('auth.google.identity_linked', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());
            } else {
                $user = User::create([
                    'first_name' => $identity->givenName ?: 'Google',
                    'last_name' => $identity->familyName ?: 'User',
                    'email' => $identity->email,
                    'google_id' => $identity->sub,
                    'auth_provider' => 'google',
                    'password' => null,
                    'status' => 'active',
                ]);
                $this->auditLog->log('auth.google.account_created', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());
            }
        }

        $this->auditLog->log('auth.google.verified', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());

        if ($user->isPhoneVerified()) {
            $token = $user->createToken('api')->plainTextToken;
            $this->auditLog->log('auth.session.created', $user, newState: ['via' => 'google'], ipAddress: $request->ip(), userAgent: $request->userAgent());

            return response()->json([
                'success' => true,
                'data' => ['access_token' => $token, 'user' => new UserResource($user)],
            ]);
        }

        if (! $user->phone) {
            return response()->json([
                'success' => true,
                'data' => ['requires_phone' => true, 'pre_auth_token' => $this->preAuth->issue($user, self::PURPOSE)],
            ]);
        }

        $this->otp->generateAndSend($user, self::PURPOSE, $request->ip(), $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['requires_otp' => true, 'pre_auth_token' => $this->preAuth->issue($user, self::PURPOSE)],
        ]);
    }

    /** Sets the phone number for a Google user with none yet, then sends the OTP. */
    public function setPhone(GoogleSetPhoneRequest $request): JsonResponse
    {
        $user = $this->preAuth->resolve($request->string('pre_auth_token'), self::PURPOSE);

        $user->forceFill(['phone' => $request->string('phone')])->save();

        $this->otp->generateAndSend($user, self::PURPOSE, $request->ip(), $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['requires_otp' => true, 'pre_auth_token' => $this->preAuth->issue($user, self::PURPOSE)],
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->preAuth->resolve($request->string('pre_auth_token'), self::PURPOSE);

        $this->otp->verify($user, self::PURPOSE, $request->string('otp_code'), $request->ip(), $request->userAgent());

        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->preAuth->invalidate($request->string('pre_auth_token'));

        $token = $user->createToken('api')->plainTextToken;

        $this->auditLog->log('auth.session.created', $user, newState: ['via' => 'google'], ipAddress: $request->ip(), userAgent: $request->userAgent());

        return response()->json([
            'success' => true,
            'data' => ['access_token' => $token, 'user' => new UserResource($user)],
        ]);
    }
}
