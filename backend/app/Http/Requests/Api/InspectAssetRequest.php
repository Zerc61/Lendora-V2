<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Selesaikan pemeriksaan aset yang menunggu. Kondisi sudah tercatat saat
 * pengembalian; catatan di sini melengkapi hasil inspeksi.
 */
class InspectAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
