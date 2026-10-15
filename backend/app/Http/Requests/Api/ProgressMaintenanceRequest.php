<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Perpindahan state pengerjaan teknis (menunggu komponen, selesai dikerjakan).
 * Catatan opsional sebagai jejak (MaintenanceLog).
 */
class ProgressMaintenanceRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
