<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanItem>
 */
class LoanItemFactory extends Factory
{
    protected $model = LoanItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'asset_id' => Asset::factory(),
        ];
    }
}
