<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CreditApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $scheduledAt = now()->startOfDay()->addDay();
        while ($scheduledAt->isWeekend()) {
            $scheduledAt->addDay();
        }
        $scheduledAt->addWeekdays($this->faker->numberBetween(0, 30));

        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_APPOINTMENT_PROPOSED,
            ]),
            'branch_id' => Branch::factory(),
            'attempt_number' => 1,
            'scheduled_date' => $scheduledAt,
            'scheduled_time' => $this->faker->randomElement(Branch::DEFAULT_SLOTS),
            'status' => Appointment::STATUS_PROPOSED,
            'is_auto_scheduled_future' => false,
            'decided_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Appointment $appointment) {
            $application = $appointment->creditApplication;

            if ($application !== null && $application->branch_id === null) {
                $application->forceFill(['branch_id' => $appointment->branch_id])->saveQuietly();
            }
        });
    }

    public function forApplication(CreditApplication $application, ?Branch $branch = null): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_application_id' => $application->getKey(),
            'branch_id' => $branch?->getKey() ?? $application->branch_id ?? Branch::factory(),
        ]);
    }

    public function attempt(int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'attempt_number' => max(1, min($number, Appointment::MAX_ATTEMPTS)),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_ACCEPTED,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_REJECTED,
            'decided_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_CANCELLED,
            'decided_at' => now(),
        ]);
    }

    public function autoScheduledFuture(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_auto_scheduled_future' => true,
        ]);
    }
}
