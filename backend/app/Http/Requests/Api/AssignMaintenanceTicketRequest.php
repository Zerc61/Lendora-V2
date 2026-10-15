<?php

namespace App\Http\Requests\Api;

use App\Models\Membership;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tugaskan teknisi. `assigned_to` harus anggota aktif organisasi yang sama.
 */
class AssignMaintenanceTicketRequest extends FormRequest
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
            'assigned_to' => [
                'nullable',
                'integer',
                Rule::exists('memberships', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('status', Membership::STATUS_ACTIVE),
            ],
        ];
    }
}
