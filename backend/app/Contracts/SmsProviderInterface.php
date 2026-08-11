<?php

namespace App\Contracts;

use App\Services\Sms\SmsDeliveryResult;

/**
 * Swappable SMS transport. Bound in AppServiceProvider based on the SMS_PROVIDER env var, so a
 * real provider (Twilio, Vonage, ...) can be plugged in later by adding one class that implements
 * this interface and one line in the binding switch — nothing else in the codebase (OtpService,
 * controllers) references a concrete provider.
 */
interface SmsProviderInterface
{
    public function send(string $to, string $message): SmsDeliveryResult;
}
