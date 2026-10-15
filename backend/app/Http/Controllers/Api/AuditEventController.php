<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Daftar jejak audit organisasi (admin only). Tabel bersifat append-only
 * lewat trigger database; endpoint ini hanya membaca, tanpa ubah atau hapus.
 */
class AuditEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $context = app(OrganizationContext::class);

        abort_unless($context->hasRole(Membership::ROLE_ADMIN), 403);

        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = AuditEvent::query()
            ->where('organization_id', $context->id())
            ->with('actor:id,name,email')
            ->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($data['actor_id'] ?? null, fn ($query, $actorId) => $query->where('actor_id', $actorId))
            ->orderByDesc('id')
            ->paginate($data['limit'] ?? 25);

        return AuditEventResource::collection($events);
    }
}
