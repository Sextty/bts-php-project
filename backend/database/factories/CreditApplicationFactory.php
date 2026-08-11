<?php

namespace Database\Factories;

use App\Models\CreditApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditApplication>
 */
class CreditApplicationFactory extends Factory
{
    protected $model = CreditApplication::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => CreditApplication::STATUS_DRAFT,
        ];
    }
}
