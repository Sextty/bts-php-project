<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mirror of EnsureStaffUser, for the customer zone: Sanctum resolves either a User or a
 * StaffUser from the same polymorphic token table, so a staff token reaching a customer route
 * must be refused explicitly rather than tripping over missing customer-only relations or
 * 500ing. Every route in the customer group runs this before any business logic.
 */
class EnsureCustomerUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            throw new ApiException(ApiErrorCode::Forbidden, 'This action requires a customer account.');
        }

        return $next($request);
    }
}
