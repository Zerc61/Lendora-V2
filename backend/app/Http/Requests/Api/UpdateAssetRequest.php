<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'location_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'purchase_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'daily_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'min_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'deposit_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'warranty_until' => [
                'sometimes',
                'nullable',
                'date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $purchaseDate = $this->input('purchase_date');

                    if ($value !== null && $purchaseDate !== null && strtotime((string) $value) < strtotime((string) $purchaseDate)) {
                        $fail('Masa garansi harus setelah tanggal beli.');
                    }
                },
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'photos' => ['sometimes', 'array', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
