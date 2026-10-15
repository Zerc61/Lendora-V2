<?php

namespace App\Http\Requests\Api;

use App\Models\Issue;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIssueRequest extends FormRequest
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
            'asset_id' => [
                'required',
                'integer',
                Rule::exists('assets', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::in(Issue::TYPES)],
            'severity' => ['required', Rule::in(Issue::SEVERITIES)],
            'description' => ['required', 'string', 'max:5000'],
            'loan_id' => [
                'nullable',
                'integer',
                Rule::exists('loans', 'id')->where('organization_id', $organizationId),
            ],
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
                // M-4: severity kritis wajib disertai minimal satu foto.
                if ($this->input('severity') !== Issue::SEVERITY_CRITICAL) {
                    return;
                }

                if (count($this->file('photos') ?? []) === 0) {
                    $validator->errors()->add(
                        'photos',
                        'Severity kritis wajib disertai minimal satu foto.',
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
            'asset_id.exists' => 'Aset tidak ditemukan.',
            'type.in' => 'Tipe masalah tidak dikenal.',
            'severity.in' => 'Severity tidak dikenal.',
            'photos.*.image' => 'Foto harus berupa berkas gambar.',
        ];
    }
}
