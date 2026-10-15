<?php

namespace App\Http\Resources;

use App\Models\Issue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Issue
 */
class IssueResource extends JsonResource
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
            'loan_id' => $this->loan_id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'severity' => $this->severity,
            'severity_label' => $this->severityLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'description' => $this->description,
            'reporter' => new UserResource($this->whenLoaded('reporter')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'photos' => AttachmentResource::collection($this->whenLoaded('photos')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
