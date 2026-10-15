<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    protected $model = Issue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Issue mengikuti organisasi asetnya.
            'organization_id' => fn (array $attributes): int => Asset::withoutOrganizationScope()
                ->whereKey($attributes['asset_id'])
                ->value('organization_id'),
            'asset_id' => Asset::factory(),
            'loan_id' => null,
            'type' => Issue::TYPE_DAMAGE,
            'severity' => Issue::SEVERITY_MEDIUM,
            'status' => Issue::STATUS_OPEN,
            'description' => fake()->sentence(),
            'reported_by' => null,
            'assigned_to' => null,
            'resolution' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function severity(string $severity): static
    {
        return $this->state(fn (): array => ['severity' => $severity]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function reviewing(): static
    {
        return $this->status(Issue::STATUS_REVIEWING);
    }

    public function resolved(?string $resolution = null): static
    {
        return $this->state(fn (): array => [
            'status' => Issue::STATUS_RESOLVED,
            'resolution' => $resolution ?? fake()->sentence(),
            'resolved_at' => now(),
        ]);
    }
}
