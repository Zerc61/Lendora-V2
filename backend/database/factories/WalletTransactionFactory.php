<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => $this->faker->numberBetween(1000, 1000000),
            'type' => $this->faker->randomElement(['topup', 'deduction', 'refund', 'adjustment']),
            'reference_id' => $this->faker->uuid,
            'status' => $this->faker->randomElement(['pending', 'success', 'failed', 'reversed']),
            'metadata' => null,
        ];
    }
}
