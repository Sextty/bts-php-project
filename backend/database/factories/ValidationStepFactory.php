<?php

namespace Database\Factories;

use App\Models\CreditApplication;
use App\Models\ValidationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationStep>
 */
class ValidationStepFactory extends Factory
{
    protected $model = ValidationStep::class;

    public function definition(): array
    {
        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_VALIDATION_1_COMPLETED,
            ]),
            'step' => ValidationStep::STEP_VALIDATION_1,
            'status' => ValidationStep::STATUS_PASSED,
            'errors' => null,
        ];
    }

    public function forApplication(CreditApplication $application): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_application_id' => $application->getKey(),
        ]);
    }

    public function validationTwo(): static
    {
        return $this->state(fn (array $attributes) => [
            'step' => ValidationStep::STEP_VALIDATION_2,
        ]);
    }

    /** @param list<string>|null $errors */
    public function failed(?array $errors = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ValidationStep::STATUS_FAILED,
            'errors' => $errors ?? ['Échec synthétique contrôlé.'],
        ]);
    }
}
