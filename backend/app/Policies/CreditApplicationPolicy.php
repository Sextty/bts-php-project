<?php

namespace App\Policies;

use App\Models\CreditApplication;
use App\Models\User;

/**
 * Pure ownership check — "is this the customer's own application." Whether the application is
 * still editable (not FINAL_LOCKED/SUBMITTED) is a business rule, not authorization, and lives
 * in CreditApplicationService::assertEditable() instead.
 */
class CreditApplicationPolicy
{
    public function view(User $user, CreditApplication $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function update(User $user, CreditApplication $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function delete(User $user, CreditApplication $application): bool
    {
        return $application->user_id === $user->id;
    }
}
