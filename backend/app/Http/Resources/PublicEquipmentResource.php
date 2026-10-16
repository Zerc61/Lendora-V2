<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Peralatan untuk pengunjung publik. Daftar-putih: tidak ada serial number,
 * harga perolehan, kondisi, lifecycle, garansi, atau catatan internal.
 *
 * Tarif ikut tampil karena pengunjung perlu tahu harga sebelum mengajukan sewa
 * (keputusan 8). Deposit dicatat, belum ditagih (keputusan 10).
 *
 * Foto belum disertakan karena layanannya masih butuh otorisasi tenant.
 *
 * @mixin Asset
 */
class PublicEquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'daily_rate' => $this->daily_rate,
            'min_days' => $this->min_days,
            'deposit_amount' => $this->deposit_amount,
            'category' => new CategoryResource($this->whenLoaded('category')),
        ];
    }
}
