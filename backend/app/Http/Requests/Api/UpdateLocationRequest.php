<?php

namespace App\Http\Requests\Api;

use App\Models\Location;
use App\Support\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255', $this->uniqueName($organizationId)],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Nama unik pada simpul tujuan. Nama dan parent efektif dihitung dari input
     * yang diberikan, agar mengganti hanya salah satunya tetap tervalidasi.
     */
    private function uniqueName(?int $organizationId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $location = Location::query()->find($this->route('location'));

            if ($location === null) {
                return;
            }

            $name = $this->input('name', $location->name);
            $parentId = $this->exists('parent_id')
                ? $this->input('parent_id')
                : $location->parent_id;

            $exists = DB::table('locations')
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->where('id', '!=', $location->getKey())
                ->where('name', $name)
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
