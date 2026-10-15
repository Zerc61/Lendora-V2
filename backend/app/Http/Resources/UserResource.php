<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'student_id' => $this->student_id,
            'city' => $this->city,
            'education_status' => $this->education_status,
            'education_status_label' => $this->educationStatusLabel(),
            'address' => $this->address,
            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->toDateString(),
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'avatar_url' => $this->avatar_path !== null
                ? Storage::disk('public')->url($this->avatar_path)
                : null,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'joined_at' => $this->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
