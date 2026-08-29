<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\ApiSecurityHeadersMiddleware;
use App\Http\Middleware\EnsureCustomerUser;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureStaffRole;
use App\Http\Middleware\EnsureStaffUser;
use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
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

        $middleware->alias([
            'staff' => EnsureStaffUser::class,
            'staff.role' => EnsureStaffRole::class,
            'customer' => EnsureCustomerUser::class,
            'permission' => EnsurePermission::class,
        ]);

        // Correlation ID for every /api request: X-Request-Id header in, X-Request-Id
        // header + log context + error-envelope field out (see RequestIdMiddleware).
        $middleware->api(prepend: [
            ApiSecurityHeadersMiddleware::class,
            RequestIdMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-flow outcomes (wrong password, expired OTP, ...) are expected, handled
        // results, not server faults — logging every one at ERROR level (Laravel's default for
        // any uncaught exception) would drown real faults in noise. Only report status>=500.
        // A unique-violation QueryException (duplicate email/phone/... ) is likewise an expected
        // client error, rendered below as 422 — not a server fault worth an ERROR log.
        $exceptions->dontReportWhen(function (Throwable $e) {
            return ($e instanceof ApiException && $e->status < 500)
                || ($e instanceof QueryException && ($e->errorInfo[0] ?? null) === '23000');
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
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'request_id' => $request->attributes->get('request_id')],
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.', 'request_id' => $request->attributes->get('request_id')],
            ], 401);
        });

        // Policy denials (e.g. loading another customer's application) — introduced by the
        // credit-application domain, the first to use Policies in this app. Laravel's handler
        // converts Illuminate\Auth\Access\AuthorizationException to this Symfony type via
        // prepareException() BEFORE custom renderers run (same reason NotFoundHttpException,
        // not ModelNotFoundException, is registered above) — confirmed live, registering the
        // pre-conversion type here never matched.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You do not have access to this resource.', 'request_id' => $request->attributes->get('request_id')],
            ], 403);
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
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 422);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found.', 'request_id' => $request->attributes->get('request_id')],
            ], 404);
        });

        // Route-level throttling (the auth routes' ->middleware('throttle:...')) — Laravel's
        // default body is an HTML quote page, which is useless to an API client; give it the
        // same envelope as every other error, with the same RATE_LIMITED code the OTP cooldown
        // already uses.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests. Please try again later.', 'request_id' => $request->attributes->get('request_id')],
            ], 429);
        });

        // A unique-constraint violation (SQLSTATE 23000 — duplicate email/phone/... ) surfaced
        // to the client as a clean 422 instead of the default 500 the API used to return for it.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*') || ($e->errorInfo[0] ?? null) !== '23000') {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'DUPLICATE_ENTRY', 'message' => 'A record with this value already exists.', 'request_id' => $request->attributes->get('request_id')],
            ], 422);
        });

        // Never expose stack traces, SQL, paths or exception messages in API responses, even
        // when APP_DEBUG was accidentally left enabled. Laravel still reports the exception.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An unexpected error occurred.',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 500);
        });
    })->create();
