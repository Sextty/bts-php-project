<?php

namespace App\Services\SyntheticData;

use App\Models\CreditApplication;
use InvalidArgumentException;

final class SyntheticStatePath
{
    /** @var list<string> */
    private const CUSTOMER_PATH = [
        CreditApplication::STATUS_DRAFT,
        CreditApplication::STATUS_STEP_1_COMPLETED,
        CreditApplication::STATUS_STEP_2_COMPLETED,
        CreditApplication::STATUS_STEP_3_COMPLETED,
        CreditApplication::STATUS_READY_FOR_VALIDATION_1,
        CreditApplication::STATUS_VALIDATION_1_COMPLETED,
        CreditApplication::STATUS_VALIDATION_2,
        CreditApplication::STATUS_FINAL_LOCKED,
        CreditApplication::STATUS_SUBMITTED,
    ];

    /** @return list<string> */
    public function for(string $status, ?string $cancelledFrom = null): array
    {
        if ($status === CreditApplication::STATUS_CANCELLED) {
            $from = $cancelledFrom ?? CreditApplication::STATUS_DRAFT;
            $base = $this->for($from);
            $base[] = CreditApplication::STATUS_CANCELLED;

            return $base;
        }

        $customerIndex = array_search($status, self::CUSTOMER_PATH, true);
        if ($customerIndex !== false) {
            return array_slice(self::CUSTOMER_PATH, 0, $customerIndex + 1);
        }

        $submitted = self::CUSTOMER_PATH;

        return match ($status) {
            CreditApplication::STATUS_STAFF_APPROVED => [...$submitted, CreditApplication::STATUS_STAFF_APPROVED],
            CreditApplication::STATUS_STAFF_REJECTED => [...$submitted, CreditApplication::STATUS_STAFF_REJECTED],
            CreditApplication::STATUS_APPROVED => [...$submitted, CreditApplication::STATUS_STAFF_APPROVED, CreditApplication::STATUS_APPROVED],
            CreditApplication::STATUS_REJECTED => [...$submitted, CreditApplication::STATUS_STAFF_APPROVED, CreditApplication::STATUS_REJECTED],
            CreditApplication::STATUS_APPOINTMENT_PROPOSED => [...$submitted, CreditApplication::STATUS_STAFF_APPROVED, CreditApplication::STATUS_APPROVED, CreditApplication::STATUS_APPOINTMENT_PROPOSED],
            CreditApplication::STATUS_APPOINTMENT_CONFIRMED => [...$submitted, CreditApplication::STATUS_STAFF_APPROVED, CreditApplication::STATUS_APPROVED, CreditApplication::STATUS_APPOINTMENT_PROPOSED, CreditApplication::STATUS_APPOINTMENT_CONFIRMED],
            CreditApplication::STATUS_APPOINTMENT_LOCKED => [...$submitted, CreditApplication::STATUS_STAFF_APPROVED, CreditApplication::STATUS_APPROVED, CreditApplication::STATUS_APPOINTMENT_PROPOSED, CreditApplication::STATUS_APPOINTMENT_LOCKED],
            default => throw new InvalidArgumentException('Unsupported credit application status: '.$status),
        };
    }

    /** @param list<string> $path */
    public function includes(array $path, string $status): bool
    {
        return in_array($status, $path, true);
    }
}
