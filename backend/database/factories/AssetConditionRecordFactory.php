<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetConditionRecord>
 */
class AssetConditionRecordFactory extends Factory
{
    protected $model = AssetConditionRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Record mengikuti organisasi asetnya.
            'organization_id' => fn (array $attributes): int => Asset::withoutOrganizationScope()
                ->whereKey($attributes['asset_id'])
                ->value('organization_id'),
            'asset_id' => Asset::factory(),
            'condition' => Asset::CONDITION_GOOD,
            'source' => AssetConditionRecord::SOURCE_STANDALONE,
            'notes' => null,
            'checklist_result' => null,
            'inspected_by' => null,
            'inspected_at' => now(),
            'loan_id' => null,
        ];
    }

    public function condition(string $condition, ?string $notes = null): static
    {
        return $this->state(fn (): array => [
            'condition' => $condition,
            'notes' => $notes,
        ]);
    }

    public function source(string $source): static
    {
        return $this->state(fn (): array => ['source' => $source]);
    }
}
