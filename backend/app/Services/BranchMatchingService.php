<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\CreditApplication;

/**
 * Picks which BTS branch handles an application's in-person appointment. Uses the project's
 * `ville` (Étape 3), not the client's — Étape 1 collects no street-level address at all
 * (birthplace/nationality/residence *country* only), so the project location is the only
 * address-like field on the application to match against.
 */
class BranchMatchingService
{
    /** Exact, case-insensitive match on ville; falls back to the configured default branch. */
    public function findForApplication(CreditApplication $application): Branch
    {
        $ville = $application->project?->ville;

        if ($ville) {
            $match = Branch::query()->whereRaw('LOWER(ville) = ?', [mb_strtolower($ville)])->first();
            if ($match) {
                return $match;
            }
        }

        $default = Branch::query()->where('is_default', true)->first();

        if (! $default) {
            // Only fires while no branches have been entered yet, or none is flagged default —
            // an operational gap to fix by adding branch data, not a bug to patch around.
            throw new ApiException(
                'NO_BRANCH_AVAILABLE',
                'No branch is configured to handle this appointment yet.',
                status: 503,
            );
        }

        return $default;
    }
}
