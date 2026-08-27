<?php

namespace Database\Factories;

use App\Models\AppNotification;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AppNotification>
 */
class AppNotificationFactory extends Factory
{
    protected $model = AppNotification::class;

    public function definition(): array
    {
        return [
            'notifiable_type' => User::class,
            'notifiable_id' => User::factory(),
            'type' => $this->faker->randomElement([
                'application.submitted',
                'staff.approved',
                'admin.approved',
                'appointment.created',
                'report.message',
            ]),
            'title' => $this->faker->sentence(5),
            'body' => $this->faker->sentence(12),
            'data' => ['synthetic' => true],
            'dedupe_key' => 'synthetic-'.$this->faker->uuid(),
            'read_at' => null,
        ];
    }

    public function forNotifiable(Model $notifiable): static
    {
        return $this->state(fn (array $attributes) => [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
        ]);
    }

    public function forStaff(?StaffUser $staff = null): static
    {
        return $this->state(fn (array $attributes) => [
            'notifiable_type' => StaffUser::class,
            'notifiable_id' => $staff?->getKey() ?? StaffUser::factory(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function withoutDedupe(): static
    {
        return $this->state(fn (array $attributes) => [
            'dedupe_key' => null,
        ]);
    }
}
