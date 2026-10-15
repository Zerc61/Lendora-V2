<?php

namespace App\Http\Resources;

use App\Models\Loan;
use App\Models\OrganizationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pickupWindow = OrganizationPolicy::resolve($this->organization_id)->pickup_window_hours;
        $isExpired = $this->isExpired($pickupWindow);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->label(),
            'is_expired' => $isExpired,
            'is_overdue' => $this->isOverdue(),
            'is_awaiting_pickup' => $this->isAwaitingPickup() && ! $isExpired,
            'days_until_due' => $this->daysUntilDue(),
            'pickup_deadline' => $this->pickupDeadline($pickupWindow)?->toIso8601String(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'purpose' => $this->purpose,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'is_late' => (bool) $this->is_late,
            'borrower' => new UserResource($this->whenLoaded('borrower')),
            'items' => LoanItemResource::collection($this->whenLoaded('items')),
            'condition_records' => AssetConditionRecordResource::collection($this->whenLoaded('conditionRecords')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
