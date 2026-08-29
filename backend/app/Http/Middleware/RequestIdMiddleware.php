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
        $startedAt = hrtime(true);
        $requestId = $this->safeIdentifier($request->header('X-Request-Id')) ?? (string) Str::uuid();
        $correlationId = $this->safeIdentifier($request->header('X-Correlation-Id')) ?? $requestId;

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext(['request_id' => $requestId, 'correlation_id' => $correlationId]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        Log::info('api.request_completed', $this->requestContext($request, $response, $startedAt));

        return $response;
    }

    /** @return array<string, int|string|null> */
    private function requestContext(Request $request, Response $response, int $startedAt): array
    {
        $actor = $request->user();
        $route = $request->route();

        return [
            'event_category' => 'http',
            'request_id' => $request->attributes->get('request_id'),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'method' => $request->method(),
            'route' => is_object($route) ? $route->uri() : $request->path(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'actor_type' => $actor ? class_basename($actor) : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'application_id' => $this->routeModelId($request->route('application')),
            'branch_id' => $this->routeModelId($request->route('branch')),
        ];
    }

    private function routeModelId(mixed $value): int|string|null
    {
        if (is_object($value) && method_exists($value, 'getKey')) {
            return $value->getKey();
        }

        return is_scalar($value) ? $value : null;
    }

    private function safeIdentifier(?string $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 128) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]+$/', $value) ? $value : null;
    }
}
