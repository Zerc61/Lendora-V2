<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengajuan sewa oleh customer. Isinya sengaja tipis: periode dan aset. Harga,
 * hari, dan status dihitung server, jadi kolom itu dilarang hadir di sini.
 */
class StoreCustomerBookingRequest extends FormRequest
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
        $organizationId = app(OrganizationContext::class)->id();

        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'assets' => ['required', 'array', 'min:1', 'max:20'],
            'assets.*' => [
                'integer',
                'distinct',
                Rule::exists('assets', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            // Harga dan status tidak pernah datang dari klien.
            'subtotal' => ['prohibited'],
            'total' => ['prohibited'],
            'days' => ['prohibited'],
            'rate_snapshot' => ['prohibited'],
            'deposit_snapshot' => ['prohibited'],
            'currency' => ['prohibited'],
            'status' => ['prohibited'],
            'borrower_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            'assets.required' => 'Pilih minimal satu peralatan.',
            'subtotal.prohibited' => 'Harga dihitung server, bukan dikirim klien.',
            'total.prohibited' => 'Harga dihitung server, bukan dikirim klien.',
            'rate_snapshot.prohibited' => 'Harga dihitung server, bukan dikirim klien.',
            'days.prohibited' => 'Jumlah hari dihitung server, bukan dikirim klien.',
            'deposit_snapshot.prohibited' => 'Deposit dicatat server, bukan dikirim klien.',
            'currency.prohibited' => 'Mata uang ditentukan server.',
            'status.prohibited' => 'Status tidak boleh ditentukan klien.',
            'borrower_id.prohibited' => 'Pemesan ditentukan dari akun yang masuk.',
        ];
    }
}
