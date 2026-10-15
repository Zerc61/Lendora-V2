<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'Waktu selesai harus setelah waktu mulai.',
            'assets.required' => 'Pilih minimal satu aset.',
        ];
    }
}
