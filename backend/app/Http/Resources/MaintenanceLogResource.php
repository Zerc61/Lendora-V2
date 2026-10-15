<?php

namespace App\Http\Resources;

use App\Models\MaintenanceLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceLog
 */
class MaintenanceLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'note' => $this->note,
            'cost' => $this->cost,
            'actor' => new UserResource($this->whenLoaded('actor')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
