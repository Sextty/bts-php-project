<?php

namespace App\Providers;

use App\Contracts\GeminiClientInterface;
use App\Contracts\SmsProviderInterface;
use App\Services\DocumentStorage\DocumentStorage;
use App\Services\DocumentStorage\LocalDocumentStorage;
use App\Services\DocumentStorage\S3CompatibleDocumentStorage;
use App\Services\Gemini\GeminiHttpClient;
use App\Services\Sms\EmailOtpDriver;
use App\Services\Sms\LogSmsDriver;
use App\Services\Sms\VonageSmsDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GeminiClientInterface::class, GeminiHttpClient::class);

        // Document storage: local disk for development, S3/S3-compatible bucket for
        // production — selected purely by the `documents` disk's driver, see
        // config/filesystems.php. Everything else depends on DocumentStorage, never on a
        // disk name or URL.
        $this->app->bind(DocumentStorage::class, function () {
            return config('filesystems.disks.documents.driver') === 's3'
                ? new S3CompatibleDocumentStorage
                : new LocalDocumentStorage;
        });

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
        // Throttle budgets keyed per user (or IP) PER ROUTE, not globally per user. Laravel's
        // bare `throttle:10,1` form keys every throttled route on the user's id alone, so a
        // customer who fills in three application steps (each throttled) burns one shared budget
        // and the final submit gets rate-limited by the unrelated document uploads — which is
        // neither the intent of a per-action limit nor what the test suite (which drives a full
        // application in a few requests) can live with. Each route keeps its own budget.
        foreach ([6, 10, 20, 30] as $limit) {
            RateLimiter::for("bts:{$limit}", function (Request $request) use ($limit) {
                $identity = ($request->user()?->getAuthIdentifier() ?? $request->ip())
                    .'|'.$request->route()?->uri();

                return Limit::perMinute($limit)->by($identity);
            });
        }
    }
}
