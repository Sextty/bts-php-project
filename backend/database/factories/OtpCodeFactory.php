<?php

namespace Database\Factories;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    protected $model = OtpCode::class;

    protected static ?string $codeHash;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => static::$codeHash ??= Hash::make('synthetic-otp-not-usable'),
            'purpose' => $this->faker->randomElement(['registration', 'login', 'password_reset']),
            'channel' => $this->faker->randomElement(['sms', 'email']),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'attempt_count' => 0,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }

    public function forPurpose(string $purpose): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => $purpose,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes) => [
            'consumed_at' => now(),
        ]);
    }

    public function withFailedAttempts(int $attempts = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'attempt_count' => max(0, min($attempts, 255)),
        ]);
    }
}
