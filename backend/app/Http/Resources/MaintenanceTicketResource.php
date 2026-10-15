<?php

namespace App\Http\Resources;

use App\Models\MaintenanceTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceTicket
 */
class MaintenanceTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => new AssetResource($this->whenLoaded('asset')),
            'issue_id' => $this->issue_id,
            'issue' => new IssueResource($this->whenLoaded('issue')),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'is_blocking' => $this->isBlocking(),
            'priority' => $this->priority,
            'priority_label' => $this->priorityLabel(),
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'description' => $this->description,
            'estimated_cost' => $this->estimated_cost,
            'actual_cost' => $this->actual_cost,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'logs' => MaintenanceLogResource::collection($this->whenLoaded('logs')),
            'condition_records' => AssetConditionRecordResource::collection(
                $this->whenLoaded('conditionRecords'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
