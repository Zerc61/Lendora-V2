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
            'payment_method' => $this->payment_method,
            'redirect_url' => $this->payload['redirect_url'] ?? null,
        ];
    }
}
