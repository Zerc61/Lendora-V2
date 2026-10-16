<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris buku besar dompet untuk daftar lintas pengguna di konsol platform.
 *
 * `user` hanya ikut bila relasinya dimuat, agar daftar tidak menambah kueri per
 * baris. Label dan arah saldo dikirim server supaya klien tidak menebak arti
 * `type` atau tanda nominal.
 *
 * @mixin WalletTransaction
 */
class WalletTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'type' => $this->type,
            'type_label' => WalletTransaction::TYPE_LABELS[$this->type] ?? $this->type,
            'status' => $this->status,
            'status_label' => WalletTransaction::STATUS_LABELS[$this->status] ?? $this->status,
            'direction' => in_array($this->type, WalletTransaction::TYPES_IN, true) ? 'in' : 'out',
            'reference_id' => $this->reference_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => new PlatformUserResource($this->whenLoaded('user')),
        ];
    }
}
