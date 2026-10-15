<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:255', $this->uniqueName($organizationId)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Nama unik per organisasi pada simpul yang sama, di antara simpul hidup, DAN
     * anak-anaknya. Pengaman database tetap ada; ini hanya pesan yang ramah.
     */
    private function uniqueName(?int $organizationId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($organizationId): void {
            $parentId = $this->input('parent_id');

            $exists = DB::table('locations')
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->where('name', $value)
                ->when(
                    $parentId === null || $parentId === '',
                    fn ($query) => $query->whereNull('parent_id'),
                    fn ($query) => $query->where('parent_id', $parentId),
                )
                ->exists();

            if ($exists) {
                $fail('Nama lokasi ini sudah dipakai di tingkat yang sama.');
            }
        };
    }
}
