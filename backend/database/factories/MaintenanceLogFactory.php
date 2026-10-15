<?php

namespace Database\Factories;

use App\Models\MaintenanceLog;
use App\Models\MaintenanceTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceLog>
 */
class MaintenanceLogFactory extends Factory
{
    protected $model = MaintenanceLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => MaintenanceTicket::factory(),
            'actor_id' => null,
            'action' => MaintenanceLog::ACTION_CREATED,
            'note' => null,
            'cost' => null,
        ];
    }
}
