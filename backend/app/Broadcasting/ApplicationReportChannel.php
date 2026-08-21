<?php

namespace App\Broadcasting;

use App\Enums\Permission;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;

/**
 * Authorization gate for the `private-application.{applicationId}.report` Reverb channel.
 *
 * Mirrors the HTTP report routes: a staff member needs the reports.view capability AND must be
 * allowed to reach that application (branch isolation, same rule as the report routes); a
 * customer gets the channel only for their own application. Anything else — including a guessed
 * id — is refused, exactly as the HTTP route would refuse it.
 *
 * The channel name carries the `private-` prefix on purpose: Reverb treats any channel WITHOUT
 * that prefix as PUBLIC and never consults this gate — the frontends subscribe via
 * echo.private('application.{id}.report'), which prepends it, so the registered name must
 * match. Kept as a channel class (instead of an inline closure) so the test suite can register
 * the very same handler on a broadcaster that actually runs the authorization logic.
 */
class ApplicationReportChannel
{
    public function join(User|StaffUser $user, int $applicationId): bool
    {
        if ($user instanceof StaffUser) {
            if ($user->status !== 'active' || ! $user->hasPermission(Permission::ReportsView)) {
                return false;
            }

            $application = CreditApplication::find($applicationId);

            return $application !== null && $user->canAccessApplication($application);
        }

        if ($user instanceof User) {
            return CreditApplication::where('id', $applicationId)->where('user_id', $user->id)->exists();
        }

        return false;
    }
}
