<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\Membership;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Borrower tidak melihat kolom internal (kondisi, lifecycle). Dia hanya
        // perlu tahu aset bisa dipinjam atau tidak. Asset Management C.1 dan D.
        $management = app(OrganizationContext::class)->hasRole(
            Membership::ROLE_OPERATOR,
            Membership::ROLE_ADMIN,
        );

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'location' => new LocationResource($this->whenLoaded('location')),
            'is_borrowable' => $this->lifecycle_status === Asset::LIFECYCLE_ACTIVE,
            // Tarif terlihat oleh semua peran: peminjam perlu tahu harga sebelum
            // mengajukan. Bukan kolom internal seperti kondisi atau harga perolehan.
            'daily_rate' => $this->daily_rate,
            'min_days' => $this->min_days,
            'deposit_amount' => $this->deposit_amount,
            'photos' => AttachmentResource::collection($this->whenLoaded('photos')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            $this->mergeWhen($management, fn (): array => [
                'lifecycle_status' => $this->lifecycle_status,
                'lifecycle_status_label' => $this->lifecycleLabel(),
                'condition' => $this->condition,
                'condition_label' => $this->conditionLabel(),
                'serial_number' => $this->serial_number,
                'purchase_date' => $this->purchase_date?->toDateString(),
                'purchase_price' => $this->purchase_price,
                'warranty_until' => $this->warranty_until?->toDateString(),
                'notes' => $this->notes,
            ]),
        ];
    }
}
