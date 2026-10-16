<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris daftar penyedia untuk konsol platform. Berbeda dari
 * {@see PublicProviderResource}: admin platform perlu melihat status verifikasi
 * dan status organisasi, yang tidak boleh muncul di katalog publik.
 *
 * @mixin Organization
 */
class PlatformProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'city' => $this->city,
            'contact_email' => $this->contact_email,
            'status' => $this->status,
            'verification_status' => $this->verification_status,
            'verification_status_label' => $this->verificationStatusLabel(),
            'is_publicly_visible' => $this->isPubliclyVisible(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
