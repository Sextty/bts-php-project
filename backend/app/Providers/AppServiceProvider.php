<?php

namespace App\Providers;

use App\Contracts\SmsProviderInterface;
use App\Services\Sms\EmailOtpDriver;
use App\Services\Sms\LogSmsDriver;
use App\Services\Sms\VonageSmsDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsProviderInterface::class, function () {
            return match (config('services.sms.provider')) {
                'email' => new EmailOtpDriver,
                'vonage' => new VonageSmsDriver(
                    config('services.vonage.api_key'),
                    config('services.vonage.api_secret'),
                    config('services.vonage.brand_name'),
                ),
                // never change OtpService or the controllers that consume the interface —
                // adding a provider only ever means a new class + a case here.
                default => new LogSmsDriver,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
