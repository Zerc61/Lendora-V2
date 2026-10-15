<?php

namespace App\Http\Requests\Api;

use App\Models\MaintenanceTicket;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceTicketRequest extends FormRequest
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
            'issue_id' => [
                'nullable',
                'integer',
                Rule::exists('issues', 'id')->where('organization_id', $organizationId),
            ],
            'priority' => ['nullable', Rule::in(MaintenanceTicket::PRIORITIES)],
            'description' => ['required', 'string', 'max:5000'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after_or_equal:scheduled_start'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'priority.in' => 'Prioritas tidak dikenal.',
            'scheduled_end.after_or_equal' => 'Waktu selesai harus setelah waktu mulai.',
            'estimated_cost.min' => 'Perkiraan biaya tidak boleh negatif.',
        ];
    }
}
