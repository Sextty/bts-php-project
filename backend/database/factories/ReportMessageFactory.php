<?php

namespace Database\Factories;

use App\Models\CreditApplication;
use App\Models\ReportMessage;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportMessage>
 */
class ReportMessageFactory extends Factory
{
    protected $model = ReportMessage::class;

    public function definition(): array
    {
        return [
            'credit_application_id' => CreditApplication::factory()->state([
                'status' => CreditApplication::STATUS_APPOINTMENT_CONFIRMED,
            ]),
            'sender_type' => ReportMessage::SENDER_CUSTOMER,
            'user_id' => null,
            'staff_user_id' => null,
            'body' => $this->faker->paragraph(),
            'attachment_path' => null,
            'attachment_name' => null,
            'attachment_type' => null,
            'attachment_size' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ReportMessage $message) {
            if ($message->sender_type === ReportMessage::SENDER_CUSTOMER) {
                $message->staff_user_id = null;

                if ($message->user_id === null && $message->credit_application_id !== null) {
                    $message->user_id = CreditApplication::query()
                        ->whereKey($message->credit_application_id)
                        ->value('user_id');
                }
            } else {
                $message->user_id = null;
            }
        });
    }

    public function forApplication(CreditApplication $application): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_application_id' => $application->getKey(),
        ]);
    }

    public function fromCustomer(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_type' => ReportMessage::SENDER_CUSTOMER,
            'user_id' => $user?->getKey(),
            'staff_user_id' => null,
        ]);
    }

    public function fromStaff(?StaffUser $staff = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_type' => ReportMessage::SENDER_STAFF,
            'user_id' => null,
            'staff_user_id' => $staff?->getKey() ?? StaffUser::factory(),
        ]);
    }

    public function withAttachment(): static
    {
        return $this->state(function (array $attributes) {
            $uuid = $this->faker->uuid();

            return [
                'attachment_path' => "synthetic-test/report-attachments/{$uuid}.txt",
                'attachment_name' => "piece-jointe-synthetique-{$uuid}.txt",
                'attachment_type' => 'text/plain',
                'attachment_size' => $this->faker->numberBetween(64, 4096),
            ];
        });
    }
}
