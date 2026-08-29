<?php

namespace App\Services;

use App\Enums\ApiErrorCode;
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
    /**
     * Matches a BTS branch for an application's in-person appointment based on project location.
     * Priority:
     * 1. Coordinates distance (if project and branch coordinates exist)
     * 2. Delegation match (preferring matching both ville and delegation, then delegation)
     * 3. Governorate (ville) match
     * 4. Fallback to default branch
     * 5. Throw NO_BRANCH_AVAILABLE if no branch exists
     */
    public function findForApplication(CreditApplication $application): Branch
    {
        $project = $application->project;

        if ($project) {
            // 1. If project coordinates and agency coordinates exist, select closest
            $projectLat = isset($project->latitude) && is_numeric($project->latitude) ? (float) $project->latitude : null;
            $projectLng = isset($project->longitude) && is_numeric($project->longitude) ? (float) $project->longitude : null;

            if ($projectLat !== null && $projectLng !== null) {
                $closest = $this->findClosestByCoordinates($projectLat, $projectLng);
                if ($closest) {
                    return $closest;
                }
            }

            $delegation = $project->delegation ? trim($project->delegation) : null;
            $ville = $project->ville ? trim($project->ville) : null;

            // 2. If delegation is available:
            if ($delegation) {
                if ($ville) {
                    $match = Branch::query()
                        ->whereRaw('LOWER(ville) = ?', [mb_strtolower($ville)])
                        ->whereRaw('LOWER(delegation) = ?', [mb_strtolower($delegation)])
                        ->orderBy('id')
                        ->first();
                    if ($match) {
                        return $match;
                    }
                }

                $match = Branch::query()
                    ->whereRaw('LOWER(delegation) = ?', [mb_strtolower($delegation)])
                    ->orderBy('id')
                    ->first();
                if ($match) {
                    return $match;
                }
            }

            // 3. Match by governorate (ville)
            if ($ville) {
                $match = Branch::query()
                    ->whereRaw('LOWER(ville) = ?', [mb_strtolower($ville)])
                    ->orderBy('id')
                    ->first();
                if ($match) {
                    return $match;
                }
            }
        }

        // 4. Fallback to default branch
        $default = Branch::query()->where('is_default', true)->orderBy('id')->first();

        if (! $default) {
            // Only fires while no branches have been entered yet, or none is flagged default —
            // an operational gap to fix by adding branch data, not a bug to patch around.
            throw new ApiException(ApiErrorCode::NoBranchAvailable);
        }

        return $default;
    }

    /**
     * Calculates distance using the Haversine formula and returns the closest branch.
     */
    public function findClosestByCoordinates(float $lat, float $lng): ?Branch
    {
        $branches = Branch::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get();

        if ($branches->isEmpty()) {
            return null;
        }

        return $branches->sortBy(function (Branch $branch) use ($lat, $lng) {
            return $this->haversineDistance($lat, $lng, (float) $branch->latitude, (float) $branch->longitude);
        })->first();
    }

    /**
     * Haversine distance in kilometers between two lat/lng points.
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
