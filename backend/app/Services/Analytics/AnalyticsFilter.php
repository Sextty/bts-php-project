<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

final readonly class AnalyticsFilter
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $branchId,
        public ?string $status,
    ) {}

    /** @return array{from:string,to:string,branch_id:?int,status:?string} */
    public function metadata(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'branch_id' => $this->branchId,
            'status' => $this->status,
        ];
    }
}
