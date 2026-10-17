<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembayaran dari sisi customer.
 *
 * `payload` Midtrans tidak pernah dikirim utuh: yang dibutuhkan hanya tautan
 * pembayarannya.
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'status_label' => Payment::STATUS_LABELS[$this->status] ?? $this->status,
            'amount' => $this->amount,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'verified_at' => $this->verified_at,
            'is_verified' => $this->isVerified(),
            'payment_method' => $this->payment_method,
            'redirect_url' => $this->payload['redirect_url'] ?? null,
            // Konteks sewa untuk buku besar Keuangan konsol platform; null di
            // pemakaian lain karena relasinya tidak dimuat.
            'created_at' => $this->created_at?->toIso8601String(),
            'loan' => $this->whenLoaded('loan', function () {
                $loan = $this->loan;

                return [
                    'id' => $loan->id,
                    'status' => $loan->status,
                    'starts_at' => $loan->starts_at?->toIso8601String(),
                    'ends_at' => $loan->ends_at?->toIso8601String(),
                    'borrower' => $loan->relationLoaded('borrower') && $loan->borrower !== null ? [
                        'name' => $loan->borrower->name,
                        'email' => $loan->borrower->email,
                    ] : null,
                    'organization_name' => $loan->relationLoaded('organization') && $loan->organization !== null
                        ? $loan->organization->name
                        : null,
                ];
            }),
        ];
    }
}
