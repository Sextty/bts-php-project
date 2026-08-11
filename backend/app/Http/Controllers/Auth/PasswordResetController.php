<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Laravel's built-in password broker (Password::sendResetLink / Password::reset) rather than a
 * reinvented flow — the new spec doesn't specify a different mechanism, and the broker already
 * gives single-use, expiring, hashed tokens (password_reset_tokens, migrated in Step 4).
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        // Same response whether or not the email exists — enumeration is not something a
        // 200/404 split should reveal. The broker's own status differentiates internally only
        // for our own audit trail, never for the client-facing response.
        $user = User::where('email', $request->string('email'))->first();
        $this->auditLog->log(
            'auth.password_reset_requested',
            $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'data' => ['message' => 'If an account exists for that email, a reset link has been sent.'],
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));

                $this->auditLog->log(
                    'auth.password_reset_completed',
                    $user,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('INVALID_RESET_TOKEN', 'This reset link is invalid or has expired.', status: 400);
        }

        return response()->json(['success' => true, 'data' => ['message' => 'Password has been reset.']]);
    }
}
