<?php

namespace App\Providers;

use App\Contracts\DocumentAiClientInterface;
use App\Contracts\SmsProviderInterface;
use App\Services\DocumentStorage\DocumentStorage;
use App\Services\DocumentStorage\LocalDocumentStorage;
use App\Services\DocumentStorage\S3CompatibleDocumentStorage;
use App\Services\DocumentVerification\LocalOnlyDocumentVerificationClient;
use App\Services\Gemini\GeminiHttpClient;
use App\Services\LoadTesting\LoadTestSafetyGate;
use App\Services\OpenRouter\OpenRouterHttpClient;
use App\Services\OperationalHeartbeatService;
use App\Services\Sms\E2EOtpDriver;
use App\Services\Sms\EmailOtpDriver;
use App\Services\Sms\LoadTestOtpDriver;
use App\Services\Sms\LogSmsDriver;
use App\Services\Sms\VonageSmsDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Local-only remains the safe default. Cloud providers are explicit opt-ins because
        // they receive customer document bytes. Gemini stays available for rollback.
        $this->app->bind(DocumentAiClientInterface::class, fn () => match (config('services.document_verification.provider')) {
            'openrouter' => new OpenRouterHttpClient,
            'gemini' => new GeminiHttpClient,
            default => new LocalOnlyDocumentVerificationClient,
        });

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
                'e2e' => new E2EOtpDriver,
                'loadtest' => new LoadTestOtpDriver(app(LoadTestSafetyGate::class)),
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
        // queue:work emits looping even while the queue is empty. This distinguishes a healthy
        // idle worker from an offline worker without inventing backlog traffic.
        Queue::looping(function (): void {
            static $lastTouch = 0;
            if (time() - $lastTouch >= 15) {
                app(OperationalHeartbeatService::class)->touchWorker();
                $lastTouch = time();
            }
        });

        // Throttle budgets keyed per user (or IP) PER ROUTE, not globally per user. Laravel's
        // bare `throttle:10,1` form keys every throttled route on the user's id alone, so a
        // customer who fills in three application steps (each throttled) burns one shared budget
        // and the final submit gets rate-limited by the unrelated document uploads — which is
        // neither the intent of a per-action limit nor what the test suite (which drives a full
        // application in a few requests) can live with. Each route keeps its own budget.
        foreach ([6, 10, 20, 30] as $limit) {
            RateLimiter::for("bts:{$limit}", function (Request $request) use ($limit) {
                $loadIdentity = null;
                if (app()->environment('loadtest') && config('load_testing.enabled', false)) {
                    $candidate = (string) $request->header('X-BTS-Synthetic-User', '');
                    if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $candidate)) {
                        $loadIdentity = 'synthetic:'.$candidate;
                    }
                }

                $identity = ($request->user()?->getAuthIdentifier() ?? $loadIdentity ?? $request->ip())
                    .'|'.$request->route()?->uri();

                return Limit::perMinute($limit)->by($identity);
            });
        }

        // Queue events are operational telemetry only: class, queue, job id and duration. The
        // job payload is deliberately never logged because it can contain notification text,
        // document metadata, or credentials for a third-party transport.
        $startedJobs = [];

        Queue::before(function (JobProcessing $event) use (&$startedJobs): void {
            $startedJobs[$event->job->getJobId()] = hrtime(true);
            Log::info('queue.job_started', $this->queueContext($event->job));
        });

        Queue::after(function (JobProcessed $event) use (&$startedJobs): void {
            $jobId = $event->job->getJobId();
            $context = $this->queueContext($event->job);
            $context['runtime_ms'] = isset($startedJobs[$jobId])
                ? max(0, (int) round((hrtime(true) - $startedJobs[$jobId]) / 1_000_000))
                : null;
            unset($startedJobs[$jobId]);
            Log::info('queue.job_completed', $context);
        });

        Queue::failing(function (JobFailed $event) use (&$startedJobs): void {
            $jobId = $event->job->getJobId();
            $context = $this->queueContext($event->job);
            $context['runtime_ms'] = isset($startedJobs[$jobId])
                ? max(0, (int) round((hrtime(true) - $startedJobs[$jobId]) / 1_000_000))
                : null;
            unset($startedJobs[$jobId]);
            Log::warning('queue.job_failed', array_merge($context, [
                'exception_class' => $event->exception::class,
            ]));
        });
    }

    /** @return array{event_category: string, job_id: string|null, job_name: string, queue: string, connection: string} */
    private function queueContext(Job $job): array
    {
        return [
            'event_category' => 'queue',
            'job_id' => $job->getJobId(),
            'job_name' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
        ];
    }
}
