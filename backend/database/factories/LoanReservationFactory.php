<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanReservation>
 */
class LoanReservationFactory extends Factory
{
    protected $model = LoanReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->startOfHour();

        return [
            'organization_id' => fn (array $attributes): int => Loan::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($attributes['loan_id'])
                ->organization_id,
            'loan_id' => Loan::factory(),
            'asset_id' => Asset::factory(),
            'status' => 'approved',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(3),
        ];
    }
}
