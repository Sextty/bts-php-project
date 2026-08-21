<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorization gate for the `private-user.{id}` Reverb channel (in-app notifications).
 * A strict owner check — a user can only hear what targets them, and staff (whose id space is
 * separate) can never claim a customer's channel. Kept as a channel class so tests can
 * re-register the very same handler on a broadcaster that runs the authorization logic.
 */
class UserChannel
{
    public function join(mixed $user, int $id): bool
    {
        return $user instanceof User && (int) $user->id === (int) $id;
    }
}
