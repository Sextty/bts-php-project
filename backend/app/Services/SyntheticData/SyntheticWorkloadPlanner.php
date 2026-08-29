<?php

namespace App\Services\SyntheticData;

final class SyntheticWorkloadPlanner
{
    private int $base;

    private int $extra;

    private int $multiplier;

    private int $offset;

    private int $throughOrdinal = 0;

    private int $throughTotal = 0;

    public function __construct(private readonly GenerationOptions $options)
    {
        $this->base = intdiv($options->applications, $options->customers);
        $this->extra = $options->applications % $options->customers;
        $this->multiplier = $this->coprimeMultiplier($options->customers);
        $this->offset = (int) (hexdec(substr(hash('sha256', $options->seed.'|distribution'), 0, 7)) % $options->customers);
    }

    public function applicationsForCustomer(int $ordinal): int
    {
        if ($ordinal < 1 || $ordinal > $this->options->customers) {
            return 0;
        }

        $residue = (int) (((($ordinal - 1) * $this->multiplier) + $this->offset) % $this->options->customers);

        return $this->base + ($residue < $this->extra ? 1 : 0);
    }

    public function applicationsThrough(int $customerOrdinal): int
    {
        $customerOrdinal = min(max($customerOrdinal, 0), $this->options->customers);
        if ($customerOrdinal < $this->throughOrdinal) {
            $this->throughOrdinal = 0;
            $this->throughTotal = 0;
        }

        for ($ordinal = $this->throughOrdinal + 1; $ordinal <= $customerOrdinal; $ordinal++) {
            $this->throughTotal += $this->applicationsForCustomer($ordinal);
        }

        $this->throughOrdinal = $customerOrdinal;

        return $this->throughTotal;
    }

    public function applicationKey(int $customerOrdinal, int $historyIndex): string
    {
        return 'c'.$customerOrdinal.'-a'.$historyIndex;
    }

    private function coprimeMultiplier(int $modulus): int
    {
        if ($modulus <= 2) {
            return 1;
        }

        $candidate = min(7_919, $modulus - 1);
        if ($candidate % 2 === 0) {
            $candidate--;
        }

        while ($candidate > 1 && $this->gcd($candidate, $modulus) !== 1) {
            $candidate -= 2;
        }

        return max(1, $candidate);
    }

    private function gcd(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return abs($left);
    }
}
