<?php

namespace App\Http\Resources;

use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pemesanan dilihat dari sisi customer.
 *
 * Rincian barang dibaca dari `pricing_snapshot`, bukan dari relasi `items.asset`:
 * snapshot tidak bergantung konteks tenant maupun kondisi aset sekarang, dan
 * tagihan yang sudah tercatat harus tetap terbaca walau asetnya diubah nanti.
 * Kolom internal aset tidak pernah ikut.
 *
 * @mixin Loan
 */
class CustomerBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->label(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'purpose' => $this->purpose,
            'rejection_reason' => $this->rejection_reason,
            'days' => $this->days,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'deposit' => $this->deposit_snapshot,
            'items' => $this->snapshotLines(),
            'provider' => $this->whenLoaded('organization', fn (): array => [
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
                'city' => $this->organization->city,
            ]),
            // Relasi pembayaran sengaja dibaca langsung, bukan lewat whenLoaded:
            // whenLoaded mengembalikan null tanpa memanggil closure saat relasi
            // termuat tetapi belum ada barisnya, sehingga `can_pay` ikut kosong.
            // Daftar booking sudah memuat relasinya, jadi tidak ada kueri per baris.
            'payment' => $this->latestPayment === null ? null : [
                'status' => $this->latestPayment->status,
                'status_label' => Payment::STATUS_LABELS[$this->latestPayment->status] ?? $this->latestPayment->status,
                'amount' => $this->latestPayment->amount,
                'expires_at' => $this->latestPayment->expires_at?->toIso8601String(),
                'paid_at' => $this->latestPayment->paid_at?->toIso8601String(),
                'is_verified' => $this->latestPayment->isVerified(),
            ],
            // Tombol bayar hanya masuk akal setelah disetujui dan sebelum lunas.
            'can_pay' => $this->status === Loan::STATUS_APPROVED
                && ! (bool) $this->latestPayment?->isPaid(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Rincian harga yang dibekukan saat pemesanan dibuat.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshotLines(): array
    {
        $lines = $this->pricing_snapshot['lines'] ?? [];

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_map(fn (array $line): array => [
            'asset_id' => $line['asset_id'] ?? null,
            'code' => $line['code'] ?? null,
            'name' => $line['name'] ?? null,
            'days' => $line['days'] ?? null,
            'daily_rate' => $line['daily_rate'] ?? null,
            'amount' => $line['amount'] ?? null,
        ], array_filter($lines, 'is_array')));
    }
}
