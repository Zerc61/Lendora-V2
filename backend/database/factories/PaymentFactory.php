<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn (array $attributes): int => Loan::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($attributes['loan_id'])
                ->organization_id,
            'loan_id' => Loan::factory(),
            'order_id' => 'LN-'.Str::ulid(),
            'amount' => '150000.00',
            'status' => Payment::STATUS_PENDING,
            'expires_at' => now()->addDays(2),
            'paid_at' => null,
            'payload' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
        ]);
    }
}
