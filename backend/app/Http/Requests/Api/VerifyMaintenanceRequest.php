<?php

namespace App\Http\Requests\Api;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Verifikasi hasil perbaikan. Wajib menyertakan kondisi baru; kondisi buruk atau
 * tidak bisa dipakai wajib disertai catatan masalah (C-6/C-7, CS-06).
 */
class VerifyMaintenanceRequest extends FormRequest
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
            'condition' => ['required', Rule::in(Asset::CONDITIONS)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'photos' => ['array', 'max:'.Issue::MAX_PHOTOS],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! in_array($this->input('condition'), AssetConditionRecord::NOTES_REQUIRED_CONDITIONS, true)) {
                    return;
                }

                if (blank($this->input('notes'))) {
                    $validator->errors()->add(
                        'notes',
                        'Kondisi buruk atau tidak bisa dipakai wajib disertai catatan.',
                    );
                }
            },
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function photos(): array
    {
        return $this->file('photos') ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'condition.in' => 'Kondisi tidak dikenal.',
            'photos.*.image' => 'Foto harus berupa berkas gambar.',
        ];
    }
}
