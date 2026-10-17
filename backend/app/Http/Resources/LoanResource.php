<?php

namespace App\Http\Resources;

use App\Models\Loan;
use App\Models\OrganizationPolicy;
use App\Models\Payment;
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
            'days' => $this->days,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'is_late' => (bool) $this->is_late,
            // Sumbu pembayaran. Hanya muncul bila relasinya dimuat, supaya daftar
            // loan tidak menambah kueri per baris.
            'payment_status' => $this->whenLoaded('latestPayment', fn (): ?string => $this->latestPayment?->status),
            'payment_status_label' => $this->whenLoaded('latestPayment', fn (): ?string => $this->latestPayment === null
                ? null
                : (Payment::STATUS_LABELS[$this->latestPayment->status] ?? $this->latestPayment->status)),
            // `whenLoaded` bawaan mengembalikan null (bukan false) saat relasinya
            // dimuat tapi kosong, jadi status bayar diperiksa langsung agar
            // pemanggil selalu menerima boolean yang jelas.
            'is_paid' => $this->resource->relationLoaded('latestPayment')
                ? (bool) $this->latestPayment?->isPaid()
                : null,
            // Dana sudah diperiksa admin (uang masuk), gerbang serah terima.
            'is_payment_verified' => $this->resource->relationLoaded('latestPayment')
                ? (bool) $this->latestPayment?->isVerified()
                : null,
            'payment_verified_at' => $this->whenLoaded('latestPayment', fn (): ?string => $this->latestPayment?->verified_at?->toIso8601String()),
            // Dipakai tombol "Verifikasi dana" di halaman peminjaman.
            'payment_id' => $this->whenLoaded('latestPayment', fn (): ?int => $this->latestPayment?->getKey()),
            'borrower' => new UserResource($this->whenLoaded('borrower')),
            'items' => LoanItemResource::collection($this->whenLoaded('items')),
            'condition_records' => AssetConditionRecordResource::collection($this->whenLoaded('conditionRecords')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
