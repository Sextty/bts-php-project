<?php

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use App\Models\User;
use App\Services\LoadTesting\LoadTestSafetyGate;
use App\ValueObjects\OtpMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Sends OTPs only to the loopback, in-memory collector started by the isolated load runner. */
final class LoadTestOtpDriver implements SmsProviderInterface
{
    public function __construct(private readonly LoadTestSafetyGate $safety) {}

    public function send(User $user, OtpMessage $message): SmsDeliveryResult
    {
        $this->safety->assertActive();
        $url = (string) config('load_testing.otp_collector_url');
        $token = (string) config('load_testing.otp_collector_token');

        if (! str_starts_with($url, 'http://127.0.0.1:') || strlen($token) < 32) {
            throw new RuntimeException('The load-test OTP collector is not safely configured.');
        }

        $response = Http::timeout(2)
            ->withToken($token)
            ->post(rtrim($url, '/').'/otp', [
                'phone' => (string) $user->phone,
                'code' => $message->code,
            ]);

        return $response->successful()
            ? SmsDeliveryResult::success()
            : SmsDeliveryResult::failure('Local synthetic OTP collector rejected delivery.');
    }

    public function canReach(User $user): bool
    {
        return $this->safety->isActive();
    }
}
