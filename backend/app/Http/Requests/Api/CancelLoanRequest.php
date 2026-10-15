<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CancelLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Alasan wajib hanya untuk admin; ditegakkan di controller (Lending C.4).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
