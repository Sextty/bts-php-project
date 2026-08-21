<?php

namespace App\Broadcasting;

use App\Enums\Role;
use App\Models\StaffUser;

/**
 * Authorization gate for the `private-admin` Reverb channel: staff who rank AT OR ABOVE admin
 * (admins and super admins). Non-staff principals are refused. Kept as a channel class so tests
 * can re-register it on a real broadcaster.
 */
class AdminChannel
{
    public function join(mixed $user): bool
    {
        return $user instanceof StaffUser
            && $user->status === 'active'
            && $user->isAtLeast(Role::Admin->value);
    }
}
