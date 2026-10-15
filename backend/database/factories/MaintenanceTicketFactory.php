<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\MaintenanceTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceTicket>
 */
class MaintenanceTicketFactory extends Factory
{
    protected $model = MaintenanceTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Ticket mengikuti organisasi asetnya.
            'organization_id' => fn (array $attributes): int => Asset::withoutOrganizationScope()
                ->whereKey($attributes['asset_id'])
                ->value('organization_id'),
            'asset_id' => Asset::factory(),
            'issue_id' => null,
            'status' => MaintenanceTicket::STATUS_OPEN,
            'priority' => Issue::SEVERITY_MEDIUM,
            'scheduled_start' => null,
            'scheduled_end' => null,
            'assigned_to' => null,
            'description' => fake()->sentence(),
            'estimated_cost' => null,
            'actual_cost' => null,
            'completed_at' => null,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function priority(string $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function assigned(): static
    {
        return $this->status(MaintenanceTicket::STATUS_ASSIGNED);
    }

    public function inProgress(): static
    {
        return $this->status(MaintenanceTicket::STATUS_IN_PROGRESS);
    }

    public function awaitingVerification(): static
    {
        return $this->status(MaintenanceTicket::STATUS_AWAITING_VERIFICATION);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceTicket::STATUS_RESOLVED,
            'completed_at' => now(),
        ]);
    }
}
