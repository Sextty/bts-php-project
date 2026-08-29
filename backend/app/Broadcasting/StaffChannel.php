<?php

namespace App\Broadcasting;

use App\Models\StaffUser;

/**
 * Authorization gate for the `private-staff` Reverb channel: every ACTIVE staff member may
 * subscribe. Branch scoping is a UI concern (notification payloads carry branch_id); a blanket
 * staff broadcast must never carry customer PII beyond what the HTTP staff endpoints already
 * expose. The parameter is deliberately untyped: any authenticated principal may try to
 * subscribe, and non-staff must be refused, not crash the auth endpoint. Kept as a channel
 * class so tests can re-register it on a real broadcaster.
 */
class StaffChannel
{
    public function join(mixed $user): bool
    {
        return $user instanceof StaffUser && $user->status === 'active';
    }
}
