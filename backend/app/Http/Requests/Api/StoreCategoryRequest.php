<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
