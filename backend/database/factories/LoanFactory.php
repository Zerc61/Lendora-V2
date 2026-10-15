<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->startOfHour();

        return [
            'organization_id' => Organization::factory(),
            'borrower_id' => User::factory(),
            'status' => Loan::STATUS_REQUESTED,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(3),
            'due_at' => null,
            'purpose' => fake()->optional()->sentence(),
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
            'cancellation_reason' => null,
            'checked_out_at' => null,
            'checked_out_by' => null,
            'returned_at' => null,
            'returned_by' => null,
            'is_late' => false,
        ];
    }

    public function approved(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Loan::STATUS_APPROVED,
            'approved_by' => $by?->getKey() ?? User::factory(),
            'approved_at' => now(),
            'due_at' => $attributes['ends_at'],
        ]);
    }

    public function checkedOut(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Loan::STATUS_CHECKED_OUT,
            'approved_by' => $by?->getKey() ?? User::factory(),
            'approved_at' => now()->subDay(),
            'checked_out_at' => now(),
            'checked_out_by' => $by?->getKey() ?? User::factory(),
            'due_at' => $attributes['ends_at'],
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => Loan::STATUS_REJECTED,
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => Loan::STATUS_CANCELLED,
        ]);
    }
}
