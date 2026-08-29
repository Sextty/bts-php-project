<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\CreditApplication;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'staff_user_id' => null,
            'credit_application_id' => null,
            'action' => 'auth.login.password_verified',
            'previous_state' => null,
            'new_state' => ['result' => 'synthetic_success'],
            'ip_address' => '192.0.2.'.$this->faker->numberBetween(1, 254),
            'user_agent' => 'BTS-Synthetic-Generator/1.0',
        ];
    }

    public function forApplication(CreditApplication $application): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $application->user_id,
            'credit_application_id' => $application->getKey(),
        ]);
    }

    public function fromCustomer(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user?->getKey() ?? User::factory(),
            'staff_user_id' => null,
        ]);
    }

    public function fromStaff(?StaffUser $staff = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'staff_user_id' => $staff?->getKey() ?? StaffUser::factory(),
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'staff_user_id' => null,
        ]);
    }

    public function statusTransition(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'credit_application.status_changed',
            'previous_state' => ['status' => $from],
            'new_state' => ['status' => $to],
        ]);
    }
}
