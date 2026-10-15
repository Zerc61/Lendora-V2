<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifikasi in-app milik user yang login. Payload datar supaya UI tidak perlu
 * membaca dua lapis (`data.*`) dan `key` tetap tersedia untuk ikon/deret.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => (string) $this->getKey(),
            'type' => $this->type,
            'key' => $data['key'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'action_url' => $data['action_url'] ?? null,
            'action_label' => $data['action_label'] ?? null,
            'loan_id' => $data['loan_id'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
