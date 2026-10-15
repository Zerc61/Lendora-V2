<?php

namespace App\Http\Requests\Api;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dasar serah terima dan pengembalian. Keduanya menangkap kondisi per aset:
 * kondisi, catatan, dan foto. Kondisi buruk atau tidak bisa dipakai wajib
 * disertai catatan dan minimal satu foto (C-6, C-7).
 */
abstract class AssetConditionCaptureRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.asset_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('assets', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'items.*.condition' => ['required', Rule::in(Asset::CONDITIONS)],
            'items.*.notes' => ['nullable', 'string', 'max:5000'],
            'items.*.photos' => ['array', 'max:'.Asset::MAX_PHOTOS],
            'items.*.photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $items = $this->input('items', []);

                foreach (array_keys($items) as $index) {
                    $condition = $this->input("items.$index.condition");

                    if (! in_array($condition, AssetConditionRecord::NOTES_REQUIRED_CONDITIONS, true)) {
                        continue;
                    }

                    if (blank($this->input("items.$index.notes"))) {
                        $validator->errors()->add(
                            "items.$index.notes",
                            'Kondisi buruk atau tidak bisa dipakai wajib disertai catatan.',
                        );
                    }

                    if (count($this->file("items.$index.photos") ?? []) === 0) {
                        $validator->errors()->add(
                            "items.$index.photos",
                            'Kondisi buruk atau tidak bisa dipakai wajib disertai minimal satu foto.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * Item yang sudah divalidasi, digabung dengan berkas foto yang diunggah.
     *
     * @return list<array{asset_id: int, condition: string, notes: ?string, photos: list<UploadedFile>}>
     */
    public function items(): array
    {
        return collect($this->validated('items'))
            ->map(fn (array $item, int|string $index): array => $item + [
                'photos' => $this->file("items.$index.photos") ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Daftar aset wajib diisi.',
            'items.*.condition.in' => 'Kondisi tidak dikenal.',
            'items.*.photos.*.image' => 'Foto harus berupa berkas gambar.',
        ];
    }
}
