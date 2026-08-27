<?php

namespace App\Services\SyntheticData;

use App\Models\Appointment;
use App\Models\CreditApplication;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class AppointmentSlotAllocator
{
    /** @var array<string, true> */
    private array $occupied = [];

    public function __construct(private readonly ConnectionInterface $connection)
    {
        $rows = $connection->table('appointments')
            ->join('credit_applications', 'credit_applications.id', '=', 'appointments.credit_application_id')
            ->whereNotIn('appointments.status', [Appointment::STATUS_REJECTED, Appointment::STATUS_CANCELLED])
            ->where('credit_applications.status', '!=', CreditApplication::STATUS_CANCELLED)
            ->get(['appointments.branch_id', 'appointments.scheduled_date', 'appointments.scheduled_time']);

        foreach ($rows as $row) {
            $this->occupied[$this->key(
                (int) $row->branch_id,
                substr((string) $row->scheduled_date, 0, 10),
                $this->normalizeTime((string) $row->scheduled_time),
            )] = true;
        }
    }

    /**
     * @param  array{id:int, slot_times:list<string>}  $branch
     * @param  array<string, true>  $excludedForApplication
     * @return array{date:string,time:string,is_auto_scheduled_future:bool,key:string}
     */
    public function allocate(array $branch, CarbonImmutable $from, bool $reserve, array $excludedForApplication = []): array
    {
        $date = $from->startOfDay();
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }
        $initialDate = $date->toDateString();

        for ($day = 0; $day < 3_650; $day++) {
            if ($date->isWeekend()) {
                $date = $date->addDay();

                continue;
            }

            foreach ($branch['slot_times'] as $slot) {
                $time = $this->normalizeTime($slot);
                $key = $this->key($branch['id'], $date->toDateString(), $time);
                if (isset($excludedForApplication[$key]) || ($reserve && isset($this->occupied[$key]))) {
                    continue;
                }

                if ($reserve) {
                    $this->occupied[$key] = true;
                }

                return [
                    'date' => $date->toDateString(),
                    'time' => $time,
                    'is_auto_scheduled_future' => $date->toDateString() !== $initialDate,
                    'key' => $key,
                ];
            }

            $date = $date->addDay();
        }

        throw new RuntimeException('No synthetic appointment slot available within ten years.');
    }

    private function key(int $branchId, string $date, string $time): string
    {
        return $branchId.'|'.$date.'|'.$time;
    }

    private function normalizeTime(string $time): string
    {
        $time = substr($time, 0, 8);

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
