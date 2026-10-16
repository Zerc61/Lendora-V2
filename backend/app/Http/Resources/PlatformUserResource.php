<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris daftar pengguna untuk admin platform. Sengaja minimal: daftar lintas
 * pengguna tidak perlu memuat seluruh data pribadi (alamat, tanggal lahir,
 * kontak darurat).
 *
 * @mixin User
 */
class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            // Saldo dompet ikut daftar: pengawas perlu melihat uang yang masih
            // beredar di akun tanpa membuka tiap pengguna satu per satu.
            'wallet_balance' => (float) $this->wallet_balance,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
