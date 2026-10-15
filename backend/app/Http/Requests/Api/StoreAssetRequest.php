<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('assets', 'code')->where('organization_id', $organizationId),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'serial_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->whereNotNull('serial_number')),
            ],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'warranty_until' => [
                'nullable',
                'date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $purchaseDate = $this->input('purchase_date');

                    if ($value !== null && $purchaseDate !== null && strtotime((string) $value) < strtotime((string) $purchaseDate)) {
                        $fail('Masa garansi harus setelah tanggal beli.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
