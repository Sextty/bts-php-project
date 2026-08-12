<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TelegramLinkStatusRequest;
use App\Services\AuditLogService;
use App\Services\OtpService;
use App\Services\PreAuthTokenService;
use App\Services\TelegramLinkService;
use Illuminate\Http\JsonResponse;

/**
 * Polled by the frontend while the user is on the "Connect Telegram" screen. Each call checks
 * whether they have pressed Start in the bot yet, and dispatches their OTP the moment they have.
 */
class TelegramLinkController extends Controller
{
    public function __construct(
        private readonly TelegramLinkService $telegramLink,
        private readonly PreAuthTokenService $preAuth,
        private readonly OtpService $otp,
        private readonly AuditLogService $auditLog,
    ) {}

    public function status(TelegramLinkStatusRequest $request): JsonResponse
    {
        $purpose = (string) $request->string('purpose');
        $user = $this->preAuth->resolve((string) $request->string('pre_auth_token'), $purpose);

        $wasLinked = (bool) $user->telegram_chat_id;

        if (! $wasLinked) {
            $this->telegramLink->pollAndLink();
            $user->refresh();
        }

        $isLinked = (bool) $user->telegram_chat_id;

        // Send on the transition only. The frontend polls this endpoint repeatedly, so keying the
        // dispatch off "is linked" rather than "just became linked" would fire a fresh code on
        // every poll — each one invalidating the previous, leaving the user chasing a code that
        // is already dead by the time they type it.
        if ($isLinked && ! $wasLinked) {
            $this->auditLog->log('telegram.linked', $user, ipAddress: $request->ip(), userAgent: $request->userAgent());
            $this->otp->generateAndSend($user, $purpose, $request->ip(), $request->userAgent());
        }

        return response()->json([
            'success' => true,
            'data' => ['linked' => $isLinked],
        ]);
    }
}
