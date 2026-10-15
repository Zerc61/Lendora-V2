<?php

namespace App\Http\Resources;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditEvent
 */
class AuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor === null
                ? null
                : [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                    'email' => $this->actor->email,
                ],
            'subject_type' => class_basename($this->subject_type),
            'subject_id' => $this->subject_id,
            'before' => $this->before,
            'after' => $this->after,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
