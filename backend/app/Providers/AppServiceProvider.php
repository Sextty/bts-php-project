<?php

namespace App\Providers;

use App\Contracts\SmsProviderInterface;
use App\Services\Sms\LogSmsDriver;
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
                // Only 'log' exists today — add a case here when a real provider is built,
                // never change OtpService or the controllers that consume the interface.
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
