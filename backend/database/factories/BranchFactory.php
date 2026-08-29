<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => 'BTS Agence '.fake()->city(),
            'ville' => fake()->city(),
            'delegation' => fake()->citySuffix(),
            'address' => fake()->streetAddress(),
            'phone' => fake()->phoneNumber(),
            'opening_hours' => '08:00 - 12:00',
            'latitude' => fake()->latitude(36, 37),
            'longitude' => fake()->longitude(9, 11),
            'daily_capacity' => 4,
            'slot_start_time' => '08:00:00',
            'slot_end_time' => '12:00:00',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
