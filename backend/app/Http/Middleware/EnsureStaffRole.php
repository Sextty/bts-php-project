<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parametrized role gate — `staff.role:admin` or `staff.role:security,admin` on a route.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        if (! $staff instanceof StaffUser) {
            throw new ApiException(ApiErrorCode::Forbidden, 'This action requires a staff account.');
        }

        $allowed = false;
        foreach ($roles as $roleArg) {
            $splitRoles = explode(',', $roleArg);
            foreach ($splitRoles as $role) {
                $role = trim($role);
                if ($staff->role === $role || $staff->isAtLeast($role)) {
                    $allowed = true;
                    break 2;
                }
            }
        }

        if (! $allowed) {
            $required = implode(' or ', $roles);
            throw new ApiException(ApiErrorCode::Forbidden, "This action requires the {$required} role.");
        }

        return $next($request);
    }
}
