<?php

namespace App\Http\Resources;

use App\Models\AssetConditionRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetConditionRecord
 */
class AssetConditionRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'loan_id' => $this->loan_id,
            'ticket_id' => $this->ticket_id,
            'condition' => $this->condition,
            'condition_label' => $this->conditionLabel(),
            'source' => $this->source,
            'source_label' => $this->sourceLabel(),
            'notes' => $this->notes,
            'checklist_result' => $this->checklist_result,
            'inspector' => new UserResource($this->whenLoaded('inspector')),
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'photos' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
