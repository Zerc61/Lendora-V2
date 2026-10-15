<?php

namespace App\Http\Requests\Api;

use App\Models\Membership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends FormRequest
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
            'role' => ['sometimes', Rule::in([
                Membership::ROLE_BORROWER,
                Membership::ROLE_OPERATOR,
                Membership::ROLE_ADMIN,
            ])],
            'status' => ['sometimes', Rule::in([
                Membership::STATUS_ACTIVE,
                Membership::STATUS_INACTIVE,
                Membership::STATUS_INVITED,
            ])],
        ];
    }
}
