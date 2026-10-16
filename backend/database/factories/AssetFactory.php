<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => 'AST-'.fake()->unique()->numerify('######'),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            // Kategori dibuat di organisasi yang sama dengan aset.
            'category_id' => fn (array $attributes): int => Category::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->getKey(),
            'location_id' => null,
            'lifecycle_status' => Asset::LIFECYCLE_ACTIVE,
            'condition' => Asset::CONDITION_GOOD,
            'serial_number' => null,
            'purchase_date' => null,
            'purchase_price' => null,
            // Aset hasil factory bisa dipesan: tanpa tarif, booking ditolak.
            'daily_rate' => '150000.00',
            'min_days' => null,
            'deposit_amount' => null,
            'warranty_until' => null,
            'notes' => null,
        ];
    }

    /**
     * Aset tanpa tarif harian: belum bisa dipesan.
     */
    public function withoutRate(): static
    {
        return $this->state(fn (): array => ['daily_rate' => null]);
    }

    public function minDays(int $days): static
    {
        return $this->state(fn (): array => ['min_days' => $days]);
    }

    public function rate(string $amount): static
    {
        return $this->state(fn (): array => ['daily_rate' => $amount]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['lifecycle_status' => Asset::LIFECYCLE_RETIRED]);
    }

    public function underMaintenance(): static
    {
        return $this->state(fn (): array => ['lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE]);
    }

    public function condition(string $condition): static
    {
        return $this->state(fn (): array => ['condition' => $condition]);
    }
}
