<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parametrized role gate — `staff.role:admin` on a route. Registered after `staff`
 * (EnsureStaffUser), so `$request->user()` is already known to be a StaffUser by the time this
 * runs; no need to re-check the type.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        if ($staff->role !== $role) {
            throw new ApiException('FORBIDDEN', "This action requires the {$role} role.", status: 403);
        }

        return $next($request);
    }
}
