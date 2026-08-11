<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Deliberately NOT calling statefulApi() here: that opts every /api/* route into
        // Sanctum's cookie-based SPA mode, which brings CSRF protection with it — but the
        // frontend authenticates with a Bearer token (Authorization header), not cookies, so
        // there is no CSRF token to present. Caught live: with statefulApi() enabled, every POST
        // from the frontend's origin 419'd ("Page Expired" / CSRF token mismatch) even though
        // the request carried a perfectly valid access token. Bearer-token auth needs neither
        // this middleware nor CSRF protection — the token itself, not an ambient cookie, is the
        // credential, so there is nothing for CSRF to protect against here.

        // API-only app: there is no `login` named route to redirect an unauthenticated request
        // to. Without this, an /api/* request that omits an explicit Accept: application/json
        // header hits Laravel's default "redirect to login" behavior and 500s with
        // "Route [login] not defined" (caught live) instead of a clean 401.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-flow outcomes (wrong password, expired OTP, ...) are expected, handled
        // results, not server faults — logging every one at ERROR level (Laravel's default for
        // any uncaught exception) would drown real faults in noise. Only report status>=500.
        $exceptions->dontReportWhen(function (\Throwable $e) {
            return $e instanceof ApiException && $e->status < 500;
        });

        // One consistent envelope for every API error: {success:false, error:{code,message}}.
        // Mirrors the error shape the platform being replaced used, which every frontend
        // component this session builds will be written to expect.
        $exceptions->render(function (ApiException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                    'fields' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found.'],
            ], 404);
        });
    })->create();
