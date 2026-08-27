<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum's personal_access_tokens table is polymorphic, so auth:sanctum alone resolves either a
 * User or a StaffUser depending on which token was presented — it never tells them apart. This
 * runs after auth:sanctum and rejects a customer token trying to reach a staff-only route (and
 * vice versa, since a StaffUser hitting a customer route would fail customer-side type checks
 * anyway, but this makes the intent explicit rather than accidental). A suspended staff account
 * is refused here too — a suspension must take effect on every endpoint, not just the ones that
 * remember to check.
 *
 * Throws ApiException rather than calling abort() so the response stays inside this app's
 * {success:false, error:{code,message}} envelope instead of Laravel's default HttpException
 * shape — there's no render() registered for a bare HttpException.
 */
class EnsureStaffUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof StaffUser) {
            throw new ApiException(ApiErrorCode::Forbidden, 'This action requires a staff account.');
        }

        if ($user->status !== 'active') {
            $user->currentAccessToken()?->delete();

            throw new ApiException(ApiErrorCode::Forbidden, 'This staff account is suspended.');
        }

        return $next($request);
    }
}
