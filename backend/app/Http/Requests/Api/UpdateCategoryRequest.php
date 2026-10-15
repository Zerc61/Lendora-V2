<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
        $categoryId = $this->route('category');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:1',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at'))
                    ->ignore($categoryId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
