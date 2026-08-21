<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parametrized role gate — `staff.role:admin` on a route. Registered after `staff`
 * (EnsureStaffUser), so `$request->user()` is already known to be a StaffUser by the time this
 * runs; no need to re-check the type.
 *
 * Hierarchy-aware via StaffUser::isAtLeast(): a super_admin passes the admin gate, a
 * branch_manager never will (final approval is bank-level). The gate is reserved for
 * role-exclusive areas (admin final decision, dashboard); ordinary routes are gated on
 * permissions instead, so future roles inherit capability without copying this middleware.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        if (! $staff->isAtLeast($role)) {
            throw new ApiException(ApiErrorCode::Forbidden, "This action requires the {$role} role.");
        }

        return $next($request);
    }
}
