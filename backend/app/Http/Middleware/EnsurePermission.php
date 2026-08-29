<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capability gate — `permission:application.review` on a route. The route may list several
 * permissions (comma-separated); any one of them suffices.
 *
 * Works for both authenticated models: a StaffUser resolves permissions from its role via
 * PermissionRegistry, a User is the client role. This is the capability half of authorization;
 * the ownership half (whose application) stays in the policies, and role-exclusive areas (admin
 * final decision, dashboard) keep the `staff.role` gate on top of it — layered, not either/or.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasAnyPermission($permissions)) {
            throw new ApiException(ApiErrorCode::Forbidden, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
