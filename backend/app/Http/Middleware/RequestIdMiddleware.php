<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation ID for every API request.
 *
 *   - Accepts a client-supplied X-Request-Id when present (so a frontend/load
 *     balancer can trace a request end to end), otherwise mints a UUID.
 *   - Adds the id to Laravel's shared log context, so every log line written
 *     during the request — including the exception handler's ERROR lines —
 *     carries the same request_id.
 *   - Echoes the id back in the X-Request-Id response header, and the API
 *     error envelope carries it too (see the exception renderers in
 *     bootstrap/app.php), so support can ask a customer for one id and search
 *     every service log for it.
 *
 * Log::withContext is safe here: the context lives on the LoggerManager
 * instance, which is rebuilt on every request boot — it cannot leak into the
 * next request, and queue jobs never traverse this middleware.
 */
class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id', (string) Str::uuid());

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}