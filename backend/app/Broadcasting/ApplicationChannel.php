<?php

namespace App\Broadcasting;

use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;

/**
 * Authorization gate for the `private-application.{applicationId}` Reverb channel: general
 * per-application live events (not the report thread, which has its own channel with the
 * reports.view capability requirement). Staff need an active account AND branch access to that
 * application (same rule as the staff show routes); a customer gets the channel only for their
 * own application. A guessed id is refused exactly as the HTTP route refuses it.
 */
class ApplicationChannel
{
    public function join(User|StaffUser $user, int $applicationId): bool
    {
        if ($user instanceof StaffUser) {
            if ($user->status !== 'active') {
                return false;
            }

            $application = CreditApplication::find($applicationId);

            return $application !== null && $user->canAccessApplication($application);
        }

        if ($user instanceof User) {
            return ! $user->isBanned()
                && $user->status === 'active'
                && CreditApplication::where('id', $applicationId)->where('user_id', $user->id)->exists();
        }

        return false;
    }
}
